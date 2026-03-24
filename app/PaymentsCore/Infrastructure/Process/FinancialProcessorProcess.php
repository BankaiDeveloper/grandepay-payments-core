<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Process;

use App\PaymentsCore\Application\Actions\ProcessInboundWebhookAction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Services\WebhookBufferService;
use App\PaymentsCore\Infrastructure\Services\WebhookLogService;
use Hyperf\Coroutine\Concurrent;
use Hyperf\Process\AbstractProcess;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;
use Swoole\Coroutine;

class FinancialProcessorProcess extends AbstractProcess
{
    public string $name = 'financial-processor';

    public int $nums = 1;

    public bool $enableCoroutine = true;

    private const CONCURRENCY = 20;

    private const POLL_INTERVAL_MS = 50;

    private const BATCH_POP_SIZE = 20;

    public function handle(): void
    {
        $bufferService = app(WebhookBufferService::class);
        $webhookLogService = app(WebhookLogService::class);
        $action = app(ProcessInboundWebhookAction::class);
        $concurrent = new Concurrent(self::CONCURRENCY);

        Log::info('FinancialProcessorProcess started', ['concurrency' => self::CONCURRENCY]);

        while (true) {
            try {
                $hasWork = false;

                for ($i = 0; $i < self::BATCH_POP_SIZE; $i++) {
                    $webhookLogId = $bufferService->popForProcessing();

                    if ($webhookLogId === null) {
                        break;
                    }

                    $hasWork = true;

                    $concurrent->create(function () use ($webhookLogId, $webhookLogService, $action): void {
                        $this->processWebhook($webhookLogId, $webhookLogService, $action);
                    });
                }

                if (! $hasWork) {
                    Coroutine::sleep(self::POLL_INTERVAL_MS / 1000);
                }
            } catch (\Throwable $e) {
                Log::error('FinancialProcessorProcess error', ['error' => $e->getMessage()]);
                Coroutine::sleep(1);
            }
        }
    }

    private function processWebhook(
        int $webhookLogId,
        WebhookLogService $webhookLogService,
        ProcessInboundWebhookAction $action,
    ): void {
        $jobUuid = (string) Str::uuid();

        try {
            $webhookLog = $webhookLogService->claimForProcessing($webhookLogId, $jobUuid);

            if (! $webhookLog instanceof WebhookLog) {
                return;
            }

            $action->execute($webhookLog);

            $webhookLogService->markProcessed($webhookLog, 200, ['processor' => 'coroutine']);
        } catch (\Throwable $e) {
            Log::error('FinancialProcessor: webhook failed', [
                'webhook_log_id' => $webhookLogId,
                'error' => $e->getMessage(),
            ]);

            $webhookLog = WebhookLog::find($webhookLogId);

            if ($webhookLog instanceof WebhookLog) {
                try {
                    $webhookLogService->markFailed($webhookLog, $e->getMessage());
                } catch (\Throwable) {
                    // Swallow status update failure
                }
            }
        }
    }
}
