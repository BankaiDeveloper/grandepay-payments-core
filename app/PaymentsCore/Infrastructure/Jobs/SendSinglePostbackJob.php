<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Jobs;

use App\PaymentsCore\Domain\Support\PostbackCircuitBreaker;
use App\PaymentsCore\Domain\Support\PostbackNetworkGuard;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\Queueable;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Log;
use JsonException;
use Throwable;

class SendSinglePostbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const HTTP_TIMEOUT = 10;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const MAX_ATTEMPTS = 8;

    private const BACKOFF_SECONDS = [5, 10, 30, 60, 120, 300, 600, 1800];

    private const LEASE_TIMEOUT_MINUTES = 5;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $postbackLogId,
    ) {
        $this->onQueue(config('payment-queues.queues.postback', 'payments-postbacks-high'));
    }

    public function handle(): void
    {
        $postback = $this->claimForProcessing();

        if (! $postback instanceof PostbackLog) {
            return;
        }

        if (! PostbackCircuitBreaker::isAvailable($postback->enterprise_id)) {
            $this->releaseLease($postback);
            $this->scheduleRetry($postback, $postback->attempts + 1);
            return;
        }

        if (! PostbackNetworkGuard::isAllowedUrl($postback->url)) {
            $postback->markAsDeadLetter('Blocked URL by security policy');
            return;
        }

        $jsonPayload = $this->resolvePayload($postback);
        if ($jsonPayload === null) {
            return;
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
        } catch (Throwable $e) {
            $this->handleFailure($postback, $e->getMessage());
            return;
        }

        if ($response->successful()) {
            $postback->markAsSent($response->status(), mb_substr($response->body(), 0, 2000));
            PostbackCircuitBreaker::recordSuccess($postback->enterprise_id);
            return;
        }

        $this->handleFailure(
            $postback,
            "HTTP {$response->status()}",
            $response->status(),
            mb_substr($response->body(), 0, 2000),
        );
    }

    private function claimForProcessing(): ?PostbackLog
    {
        return DB::transaction(function (): ?PostbackLog {
            $postback = PostbackLog::query()
                ->with('enterprise:id,settings')
                ->where('id', $this->postbackLogId)
                ->lockForUpdate()
                ->first();

            if (! $postback instanceof PostbackLog) {
                return null;
            }

            if (! in_array($postback->status, [PostbackLog::STATUS_PENDING, PostbackLog::STATUS_FAILED], true)) {
                return null;
            }

            if ($postback->locked_at !== null && $postback->locked_at->gt(now()->subMinutes(self::LEASE_TIMEOUT_MINUTES))) {
                return null;
            }

            $postback->update([
                'locked_at' => now(),
                'processing_job_uuid' => (string) \Hypervel\Support\Str::uuid(),
            ]);

            return $postback;
        });
    }

    private function releaseLease(PostbackLog $postback): void
    {
        $postback->update(['locked_at' => null, 'processing_job_uuid' => null]);
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
        } catch (JsonException $e) {
            $postback->markAsDeadLetter('JSON encoding failed: ' . $e->getMessage());
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
}
