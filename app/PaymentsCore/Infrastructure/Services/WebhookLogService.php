<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use Hypervel\Database\QueryException;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;

class WebhookLogService
{
    public function ingestIncoming(
        Request $request,
        int $paymentProviderId,
        string $idempotencyKey,
        ?string $eventType = null,
        ?int $enterpriseId = null,
        ?bool $signatureValid = null,
        ?string $signatureError = null,
    ): WebhookLog {
        $payload = [
            'payment_provider_id' => $paymentProviderId,
            'enterprise_id' => $enterpriseId,
            'event_type' => $eventType,
            'provider_event_id' => $request->input('id') ?? $request->input('event_id'),
            'idempotency_key' => $idempotencyKey,
            'ip_address' => $request->ip(),
            'headers' => $request->getHeaders(),
            'payload' => $request->all(),
            'raw_body' => (string) $request->getBody(),
            'request_path' => $request->path(),
            'request_method' => $request->method(),
            'processed' => false,
            'processing_status' => WebhookLog::STATUS_RECEIVED,
            'signature_valid' => $signatureValid,
            'signature_error' => $signatureError,
        ];

        try {
            $webhookLog = WebhookLog::query()->create(array_merge($payload, [
                'uuid' => (string) Str::uuid(),
                'attempts_count' => 0,
            ]));
        } catch (QueryException $exception) {
            if (! $this->isUniqueIdempotencyViolation($exception)) {
                throw $exception;
            }

            $webhookLog = $this->handleDuplicateWebhook($paymentProviderId, $idempotencyKey, $payload);
        }

        return $webhookLog;
    }

    public function shouldDispatchProcessing(WebhookLog $log): bool
    {
        if ($log->signature_valid === false) {
            return false;
        }

        return in_array($log->processing_status, [
            WebhookLog::STATUS_RECEIVED,
            WebhookLog::STATUS_FAILED,
        ], true);
    }

    public function claimForProcessing(int $webhookLogId, string $jobUuid): ?WebhookLog
    {
        return DB::transaction(function () use ($webhookLogId, $jobUuid): ?WebhookLog {
            $webhookLog = WebhookLog::query()
                ->whereKey($webhookLogId)
                ->lockForUpdate()
                ->first();

            if (! $webhookLog instanceof WebhookLog) {
                return null;
            }

            if (in_array($webhookLog->processing_status, [
                WebhookLog::STATUS_PROCESSED,
                WebhookLog::STATUS_IGNORED,
            ], true)) {
                return null;
            }

            $isFreshLock = $webhookLog->processing_status === WebhookLog::STATUS_PROCESSING
                && $webhookLog->locked_at !== null
                && $webhookLog->locked_at->gt(now()->subMinutes(15));

            if ($isFreshLock) {
                return null;
            }

            $webhookLog->update([
                'processed' => false,
                'processing_status' => WebhookLog::STATUS_PROCESSING,
                'attempts_count' => $webhookLog->attempts_count + 1,
                'last_attempt_at' => now(),
                'locked_at' => now(),
                'processing_job_uuid' => $jobUuid,
                'processing_error' => null,
            ]);

            return $webhookLog->refresh();
        });
    }

    public function markProcessed(
        WebhookLog $log,
        int $responseCode,
        ?array $responseBody = null,
        string $processingStatus = WebhookLog::STATUS_PROCESSED,
    ): WebhookLog {
        $log->update([
            'processed' => true,
            'processing_status' => $processingStatus,
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'processing_error' => null,
            'locked_at' => null,
            'processing_job_uuid' => null,
            'next_retry_at' => null,
            'processed_at' => now(),
        ]);

        Log::info('Webhook processed', [
            'webhook_log_uuid' => $log->uuid,
            'response_code' => $responseCode,
            'processing_status' => $processingStatus,
        ]);

        return $log->refresh();
    }

    public function markFailed(WebhookLog $log, string $error): WebhookLog
    {
        $log->update([
            'processed' => false,
            'processing_status' => WebhookLog::STATUS_FAILED,
            'processing_error' => $error,
            'locked_at' => null,
            'processing_job_uuid' => null,
            'processed_at' => now(),
        ]);

        Log::warning('Webhook processing failed', [
            'webhook_log_uuid' => $log->uuid,
            'error' => $error,
        ]);

        return $log->refresh();
    }

    private function handleDuplicateWebhook(int $paymentProviderId, string $idempotencyKey, array $payload): WebhookLog
    {
        return DB::transaction(function () use ($paymentProviderId, $idempotencyKey, $payload): WebhookLog {
            $existing = WebhookLog::query()
                ->where('payment_provider_id', $paymentProviderId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($existing->processed || in_array($existing->processing_status, [
                WebhookLog::STATUS_PROCESSING,
                WebhookLog::STATUS_PROCESSED,
                WebhookLog::STATUS_IGNORED,
            ], true)) {
                return $existing;
            }

            $updates = [
                'event_type' => $existing->event_type ?? $payload['event_type'],
                'provider_event_id' => $existing->provider_event_id ?? $payload['provider_event_id'],
                'ip_address' => $payload['ip_address'],
                'headers' => $payload['headers'],
                'payload' => $payload['payload'],
                'raw_body' => $payload['raw_body'],
                'request_path' => $payload['request_path'],
                'request_method' => $payload['request_method'],
                'signature_valid' => $payload['signature_valid'],
                'signature_error' => $payload['signature_error'],
            ];

            if ($existing->processing_status === WebhookLog::STATUS_FAILED) {
                $updates['processing_status'] = WebhookLog::STATUS_RECEIVED;
                $updates['processing_error'] = null;
                $updates['locked_at'] = null;
                $updates['processing_job_uuid'] = null;
            }

            $existing->update($updates);

            return $existing->refresh();
        });
    }

    private function isUniqueIdempotencyViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $sqlState = (string) $exception->getCode();

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($message, 'webhook_logs_provider_idempotency_unique');
    }
}
