<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Redis;

final class WebhookBufferService
{
    private const BUFFER_KEY = 'webhooks:buffer';
    private const PROCESS_KEY = 'webhooks:process';
    private const POSTBACK_KEY = 'postbacks:deliver';

    public function buffer(
        string $providerCode,
        string $idempotencyKey,
        ?string $eventType,
        string $rawBody,
        array $headers,
        string $ipAddress,
        string $requestPath,
        string $requestMethod,
    ): void {
        $envelope = json_encode([
            'provider_code' => $providerCode,
            'idempotency_key' => $idempotencyKey,
            'event_type' => $eventType,
            'raw_body' => $rawBody,
            'headers' => $headers,
            'ip_address' => $ipAddress,
            'request_path' => $requestPath,
            'request_method' => $requestMethod,
            'payload' => json_decode($rawBody, true) ?: [],
            'buffered_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Redis::rpush(self::BUFFER_KEY, $envelope);
    }

    /**
     * Pop a batch of buffered webhooks from Redis.
     *
     * @return list<array<string, mixed>>
     */
    public function popBatch(int $batchSize = 100): array
    {
        $items = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $raw = Redis::lpop(self::BUFFER_KEY);

            if ($raw === null || $raw === false) {
                break;
            }

            $decoded = json_decode((string) $raw, true);

            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    public function pushForProcessing(int $webhookLogId): void
    {
        Redis::rpush(self::PROCESS_KEY, (string) $webhookLogId);
    }

    public function popForProcessing(): ?int
    {
        $result = Redis::lpop(self::PROCESS_KEY);

        return $result !== null && $result !== false ? (int) $result : null;
    }

    public function pushForPostback(int $postbackLogId): void
    {
        Redis::rpush(self::POSTBACK_KEY, (string) $postbackLogId);
    }

    public function popForPostback(): ?int
    {
        $result = Redis::lpop(self::POSTBACK_KEY);

        return $result !== null && $result !== false ? (int) $result : null;
    }

    public function bufferSize(): int
    {
        return (int) Redis::llen(self::BUFFER_KEY);
    }

    public function processQueueSize(): int
    {
        return (int) Redis::llen(self::PROCESS_KEY);
    }

    public function postbackQueueSize(): int
    {
        return (int) Redis::llen(self::POSTBACK_KEY);
    }
}
