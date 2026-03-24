<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Process;

use App\PaymentsCore\Infrastructure\Models\PaymentProvider;
use App\PaymentsCore\Infrastructure\Services\WebhookBufferService;
use Hyperf\Process\AbstractProcess;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;
use Swoole\Coroutine;

class WebhookIngestProcess extends AbstractProcess
{
    public string $name = 'webhook-ingest';

    public int $nums = 1;

    public bool $enableCoroutine = true;

    private const BATCH_SIZE = 100;

    private const POLL_INTERVAL_MS = 50;

    public function handle(): void
    {
        $bufferService = app(WebhookBufferService::class);

        Log::info('WebhookIngestProcess started');

        while (true) {
            try {
                $batch = $bufferService->popBatch(self::BATCH_SIZE);

                if ($batch === []) {
                    Coroutine::sleep(self::POLL_INTERVAL_MS / 1000);
                    continue;
                }

                $this->processBatch($batch, $bufferService);
            } catch (\Throwable $e) {
                Log::error('WebhookIngestProcess error', ['error' => $e->getMessage()]);
                Coroutine::sleep(1);
            }
        }
    }

    private function processBatch(array $batch, WebhookBufferService $bufferService): void
    {
        $providerCache = [];
        $rows = [];
        $now = now()->format('Y-m-d H:i:s');

        foreach ($batch as $item) {
            $providerCode = $item['provider_code'] ?? '';

            if (! isset($providerCache[$providerCode])) {
                $providerCache[$providerCode] = Cache::remember(
                    "payment_provider:code:{$providerCode}",
                    300,
                    fn () => PaymentProvider::query()->where('code', $providerCode)->first(),
                );
            }

            $provider = $providerCache[$providerCode];

            if (! $provider) {
                Log::warning('WebhookIngest: provider not found', ['code' => $providerCode]);
                continue;
            }

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'payment_provider_id' => $provider->id,
                'idempotency_key' => $item['idempotency_key'] ?? hash('sha256', $item['raw_body'] ?? ''),
                'event_type' => $item['event_type'] ?? null,
                'ip_address' => $item['ip_address'] ?? null,
                'headers' => json_encode($item['headers'] ?? []),
                'payload' => json_encode($item['payload'] ?? []),
                'raw_body' => $item['raw_body'] ?? '',
                'request_path' => $item['request_path'] ?? '',
                'request_method' => $item['request_method'] ?? 'POST',
                'provider_event_id' => $item['payload']['id'] ?? $item['payload']['event_id'] ?? null,
                'processed' => false,
                'processing_status' => 'received',
                'attempts_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        $insertedIds = $this->batchInsert($rows);

        foreach ($insertedIds as $id) {
            $bufferService->pushForProcessing($id);
        }

        Log::info('WebhookIngest: batch processed', [
            'buffered' => count($batch),
            'inserted' => count($insertedIds),
        ]);
    }

    /**
     * @return list<int>
     */
    private function batchInsert(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $columns = array_keys($rows[0]);
        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $col) {
                $bindings[] = $row[$col];
                $rowPlaceholders[] = '?';
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $columnList = '"' . implode('", "', $columns) . '"';
        $sql = "INSERT INTO webhook_logs ({$columnList}) VALUES "
            . implode(', ', $placeholders)
            . " ON CONFLICT (payment_provider_id, idempotency_key) DO NOTHING"
            . " RETURNING id";

        try {
            $result = DB::select($sql, $bindings);

            return array_map(fn ($row) => (int) $row->id, $result);
        } catch (\Throwable $e) {
            Log::error('WebhookIngest: batch insert failed, falling back to individual', [
                'error' => $e->getMessage(),
                'batch_size' => count($rows),
            ]);

            return $this->fallbackIndividualInsert($rows);
        }
    }

    /**
     * @return list<int>
     */
    private function fallbackIndividualInsert(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            try {
                $columns = array_keys($row);
                $columnList = '"' . implode('", "', $columns) . '"';
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                $result = DB::select(
                    "INSERT INTO webhook_logs ({$columnList}) VALUES ({$placeholders}) ON CONFLICT (payment_provider_id, idempotency_key) DO NOTHING RETURNING id",
                    array_values($row),
                );

                if (! empty($result)) {
                    $ids[] = (int) $result[0]->id;
                }
            } catch (\Throwable $e) {
                Log::warning('WebhookIngest: individual insert failed', [
                    'idempotency_key' => $row['idempotency_key'] ?? '?',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ids;
    }
}
