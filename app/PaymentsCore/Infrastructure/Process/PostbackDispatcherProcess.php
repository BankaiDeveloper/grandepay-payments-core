<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Process;

use App\PaymentsCore\Domain\Support\PostbackCircuitBreaker;
use App\PaymentsCore\Domain\Support\PostbackNetworkGuard;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use Hyperf\Coroutine\Concurrent;
use Hyperf\Process\AbstractProcess;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Log;
use Swoole\Coroutine;

class PostbackDispatcherProcess extends AbstractProcess
{
    public string $name = 'postback-dispatcher';

    public int $nums = 1;

    public bool $enableCoroutine = true;

    private const CONCURRENCY = 50;

    private const BATCH_SIZE = 50;

    private const POLL_INTERVAL_MS = 100;

    private const HTTP_TIMEOUT = 10;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const MAX_ATTEMPTS = 8;

    private const BACKOFF_SECONDS = [5, 10, 30, 60, 120, 300, 600, 1800];

    public function handle(): void
    {
        $concurrent = new Concurrent(self::CONCURRENCY);

        Log::info('PostbackDispatcherProcess started', ['concurrency' => self::CONCURRENCY]);

        while (true) {
            try {
                $postbacks = PostbackLog::query()
                    ->with('enterprise:id,settings')
                    ->whereIn('status', [PostbackLog::STATUS_PENDING, PostbackLog::STATUS_FAILED])
                    ->where(function ($q) {
                        $q->whereNull('next_retry_at')
                            ->orWhere('next_retry_at', '<=', now());
                    })
                    ->where(function ($q) {
                        $q->whereNull('locked_at')
                            ->orWhere('locked_at', '<', now()->subMinutes(5));
                    })
                    ->orderBy('id')
                    ->limit(self::BATCH_SIZE)
                    ->get();

                if ($postbacks->isEmpty()) {
                    Coroutine::sleep(self::POLL_INTERVAL_MS / 1000);
                    continue;
                }

                foreach ($postbacks as $postback) {
                    $concurrent->create(function () use ($postback): void {
                        $this->deliverPostback($postback);
                    });
                }
            } catch (\Throwable $e) {
                Log::error('PostbackDispatcherProcess error', ['error' => $e->getMessage()]);
                Coroutine::sleep(1);
            }
        }
    }

    private function deliverPostback(PostbackLog $postback): void
    {
        try {
            $claimed = $this->claim($postback);

            if (! $claimed) {
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

            if ($response->successful()) {
                $postback->markAsSent($response->status(), mb_substr($response->body(), 0, 2000));
                PostbackCircuitBreaker::recordSuccess($postback->enterprise_id);
                return;
            }

            $this->handleFailure($postback, "HTTP {$response->status()}", $response->status(), mb_substr($response->body(), 0, 2000));
        } catch (\Throwable $e) {
            $this->handleFailure($postback, $e->getMessage());
        }
    }

    private function claim(PostbackLog $postback): bool
    {
        return DB::transaction(function () use ($postback): bool {
            $locked = PostbackLog::query()
                ->where('id', $postback->id)
                ->whereIn('status', [PostbackLog::STATUS_PENDING, PostbackLog::STATUS_FAILED])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if ($locked->locked_at !== null && $locked->locked_at->gt(now()->subMinutes(5))) {
                return false;
            }

            $locked->update([
                'locked_at' => now(),
                'processing_job_uuid' => (string) \Hypervel\Support\Str::uuid(),
            ]);

            return true;
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
        } catch (\JsonException $e) {
            $postback->markAsDeadLetter('JSON encoding failed: ' . $e->getMessage());
            return null;
        }
    }

    private function resolveSignature(PostbackLog $postback, string $jsonPayload): string
    {
        $settings = is_array($postback->enterprise?->settings) ? $postback->enterprise->settings : [];
        $secretKey = $settings['secret_key'] ?? '';

        if ($secretKey !== '') {
            return hash_hmac('sha256', $jsonPayload, $secretKey);
        }

        return $postback->signature ?? '';
    }
}
