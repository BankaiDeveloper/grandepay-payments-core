<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\Support\PostbackCircuitBreaker;
use App\PaymentsCore\Domain\Support\PostbackNetworkGuard;
use App\PaymentsCore\Domain\ValueObjects\PostbackDeliveryResult;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;
use JsonException;
use Throwable;

final class PostbackDeliveryService
{
    private const HTTP_TIMEOUT = 10;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const MAX_ATTEMPTS = 8;

    private const BACKOFF_SECONDS = [5, 10, 30, 60, 120, 300, 600, 1800];

    public function claimSingleForProcessing(int $postbackLogId): ?PostbackLog
    {
        return DB::transaction(function () use ($postbackLogId): ?PostbackLog {
            $postback = PostbackLog::query()
                ->with('enterprise:id,settings')
                ->where('id', $postbackLogId)
                ->lockForUpdate()
                ->first();

            if (! $postback instanceof PostbackLog) {
                return null;
            }

            if (! in_array($postback->status, [PostbackLog::STATUS_PENDING, PostbackLog::STATUS_FAILED], true)) {
                return null;
            }

            if ($postback->locked_at !== null && $postback->locked_at->gt(now()->subMinutes($this->leaseTimeoutMinutes()))) {
                return null;
            }

            $postback->update([
                'locked_at' => now(),
                'processing_job_uuid' => (string) Str::uuid(),
            ]);

            return $postback->refresh();
        });
    }

    public function deliver(PostbackLog $postback): PostbackDeliveryResult
    {
        $postback->loadMissing('enterprise:id,settings');

        if (! PostbackCircuitBreaker::isAvailable($postback->enterprise_id)) {
            $this->releaseLease($postback);
            $this->scheduleRetry($postback, $postback->attempts + 1);

            return new PostbackDeliveryResult(
                postbackLogId: $postback->id,
                sent: false,
                status: 'deferred',
            );
        }

        if (! PostbackNetworkGuard::isAllowedUrl($postback->url)) {
            $postback->markAsDeadLetter('Blocked URL by security policy');

            return new PostbackDeliveryResult(
                postbackLogId: $postback->id,
                sent: false,
                status: PostbackLog::STATUS_DEAD_LETTER,
            );
        }

        $jsonPayload = $this->resolvePayload($postback);
        if ($jsonPayload === null) {
            return new PostbackDeliveryResult(
                postbackLogId: $postback->id,
                sent: false,
                status: PostbackLog::STATUS_DEAD_LETTER,
            );
        }

        $signature = $this->resolveSignature($postback, $jsonPayload);

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Signature-256' => $signature,
                    'X-Event' => $postback->event,
                    'X-Postback-Id' => $postback->uuid,
                    'X-Timestamp' => now()->toIso8601String(),
                    'User-Agent' => 'GrandePay-Postback/1.0',
                ])
                ->withBody($jsonPayload, 'application/json')
                ->post($postback->url);
        } catch (Throwable $exception) {
            $this->handleFailure($postback, $exception->getMessage());

            return new PostbackDeliveryResult(
                postbackLogId: $postback->id,
                sent: false,
                status: PostbackLog::STATUS_FAILED,
            );
        }

        if ($response->successful()) {
            $postback->markAsSent($response->status(), mb_substr($response->body(), 0, 2000));
            PostbackCircuitBreaker::recordSuccess($postback->enterprise_id);

            return new PostbackDeliveryResult(
                postbackLogId: $postback->id,
                sent: true,
                status: PostbackLog::STATUS_SENT,
            );
        }

        $this->handleFailure(
            $postback,
            "HTTP {$response->status()}",
            $response->status(),
            mb_substr($response->body(), 0, 2000),
        );

        return new PostbackDeliveryResult(
            postbackLogId: $postback->id,
            sent: false,
            status: PostbackLog::STATUS_FAILED,
        );
    }

    private function releaseLease(PostbackLog $postback): void
    {
        $postback->update([
            'locked_at' => null,
            'processing_job_uuid' => null,
        ]);
    }

    private function handleFailure(PostbackLog $postback, string $errorMessage, ?int $httpStatus = null, ?string $responseBody = null): void
    {
        $attempt = $postback->attempts + 1;
        $postback->markAsFailed($attempt, $errorMessage, $httpStatus, $responseBody);

        PostbackCircuitBreaker::recordFailure($postback->enterprise_id);

        $this->scheduleRetry($postback, $attempt);
    }

    private function scheduleRetry(PostbackLog $postback, int $attempt): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            $postback->markAsDeadLetter('Max attempts (' . self::MAX_ATTEMPTS . ') reached');
            Log::error('Postback permanently failed', [
                'postback_log_id' => $postback->id,
                'enterprise_id' => $postback->enterprise_id,
                'attempts' => $attempt,
            ]);

            return;
        }

        $delay = self::BACKOFF_SECONDS[$attempt - 1] ?? 1800;
        $postback->update(['next_retry_at' => now()->addSeconds($delay)]);
    }

    private function resolvePayload(PostbackLog $postback): ?string
    {
        $jsonPayload = $postback->signed_payload;

        if (is_string($jsonPayload) && $jsonPayload !== '') {
            return $jsonPayload;
        }

        try {
            return json_encode($postback->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            $postback->markAsDeadLetter('JSON encoding failed: ' . $exception->getMessage());

            return null;
        }
    }

    private function resolveSignature(PostbackLog $postback, string $jsonPayload): string
    {
        if ($postback->enterprise !== null && is_string($postback->enterprise->secret_key ?? null)) {
            return hash_hmac('sha256', $jsonPayload, $postback->enterprise->secret_key);
        }

        return $postback->signature ?? '';
    }

    private function leaseTimeoutMinutes(): int
    {
        return max(1, (int) config('postbacks.dispatcher.lease_timeout_minutes', 5));
    }
}
