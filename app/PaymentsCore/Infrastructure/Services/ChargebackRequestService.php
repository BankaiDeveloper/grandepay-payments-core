<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\Support\ChargebackIdempotencyKeyResolver;
use App\PaymentsCore\Domain\ValueObjects\NormalizedWebhookData;
use App\PaymentsCore\Infrastructure\Jobs\ProcessChargebackRequestJob;
use App\PaymentsCore\Infrastructure\Models\ChargebackRequest;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use DomainException;
use Hypervel\Database\QueryException;
use Hypervel\Support\Facades\DB;

final class ChargebackRequestService
{
    private const PROCESSING_LOCK_TIMEOUT_MINUTES = 15;

    public function processWebhookChargeback(
        Transaction $transaction,
        WebhookLog $webhookLog,
        string $providerCode,
        NormalizedWebhookData $data,
    ): ChargebackRequest {
        return DB::transaction(function () use ($transaction, $webhookLog, $providerCode, $data): ChargebackRequest {
            $idempotencyKey = ChargebackIdempotencyKeyResolver::resolve(
                explicitKey: null,
                source: ChargebackRequest::SOURCE_WEBHOOK,
                transaction: $transaction,
                amountCents: $transaction->amount_cents,
            );

            $existing = $this->findExistingRequest(
                $transaction->id,
                ChargebackRequest::SOURCE_WEBHOOK,
                $idempotencyKey,
            );

            if ($existing instanceof ChargebackRequest) {
                return $existing;
            }

            $chargebackableStatuses = [
                Transaction::STATUS_PAID,
                Transaction::STATUS_CHARGEBACK,
            ];

            if (! in_array($transaction->status, $chargebackableStatuses, true)) {
                throw new DomainException(
                    "Transaction {$transaction->uuid} is not in a valid status for chargeback."
                );
            }

            try {
                return ChargebackRequest::query()->create([
                    'original_transaction_id' => $transaction->id,
                    'enterprise_id' => $transaction->enterprise_id,
                    'payment_provider_id' => $transaction->payment_provider_id,
                    'webhook_log_id' => $webhookLog->id,
                    'source' => ChargebackRequest::SOURCE_WEBHOOK,
                    'execution_mode' => ChargebackRequest::EXECUTION_INTERNAL_ADJUSTMENT,
                    'status' => ChargebackRequest::STATUS_COMPLETED,
                    'idempotency_key' => $idempotencyKey,
                    'amount_cents' => $transaction->amount_cents,
                    'provider_reference' => $data->providerTransactionId,
                    'provider_end_to_end_id' => $data->endToEndId,
                    'provider_status' => $data->rawPayload['status'] ?? null,
                    'provider_response_payload' => $data->rawPayload,
                    'processed_at' => now(),
                    'metadata' => [
                        'source' => 'webhook_chargeback',
                        'provider_code' => $providerCode,
                    ],
                ]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23505') {
                    $existing = $this->findExistingRequest(
                        $transaction->id,
                        ChargebackRequest::SOURCE_WEBHOOK,
                        $idempotencyKey,
                    );

                    if ($existing) {
                        return $existing;
                    }
                }

                throw $e;
            }
        });
    }

    public function requestManualChargeback(
        Transaction $transaction,
        int $requestedByUserId,
        string $source,
        ?string $reasonCode = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?string $requestId = null,
    ): array {
        return DB::transaction(function () use ($transaction, $requestedByUserId, $source, $reasonCode, $reason, $idempotencyKey, $requestId): array {
            $normalizedIdempotencyKey = ChargebackIdempotencyKeyResolver::resolve(
                explicitKey: $idempotencyKey,
                source: $source,
                transaction: $transaction,
                amountCents: $transaction->amount_cents,
            );

            $existing = $this->findExistingRequest($transaction->id, $source, $normalizedIdempotencyKey);

            if ($existing instanceof ChargebackRequest) {
                return ['chargeback_request' => $existing, 'replayed' => true];
            }

            if ($transaction->status !== Transaction::STATUS_PAID) {
                throw new DomainException('Only paid transactions can receive a chargeback.');
            }

            $supportsProviderApi = $this->supportsProviderApiChargeback($transaction);

            try {
                $chargebackRequest = ChargebackRequest::query()->create([
                    'original_transaction_id' => $transaction->id,
                    'enterprise_id' => $transaction->enterprise_id,
                    'payment_provider_id' => $transaction->payment_provider_id,
                    'requested_by_user_id' => $requestedByUserId,
                    'source' => $source,
                    'execution_mode' => $supportsProviderApi
                        ? ChargebackRequest::EXECUTION_PROVIDER_API
                        : ChargebackRequest::EXECUTION_INTERNAL_ADJUSTMENT,
                    'status' => ChargebackRequest::STATUS_PENDING_APPROVAL,
                    'reason_code' => $reasonCode,
                    'idempotency_key' => $normalizedIdempotencyKey,
                    'request_id' => $requestId,
                    'amount_cents' => $transaction->amount_cents,
                    'reason' => $reason,
                    'metadata' => ['source' => "{$source}_chargeback_request"],
                ]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23505') {
                    $existing = $this->findExistingRequest($transaction->id, $source, $normalizedIdempotencyKey);
                    if ($existing) {
                        return ['chargeback_request' => $existing, 'replayed' => true];
                    }
                }
                throw $e;
            }

            return ['chargeback_request' => $chargebackRequest, 'replayed' => false];
        });
    }

    public function approveChargeback(ChargebackRequest $chargebackRequest, int $reviewedByUserId): ChargebackRequest
    {
        return DB::transaction(function () use ($chargebackRequest, $reviewedByUserId): ChargebackRequest {
            $locked = ChargebackRequest::query()
                ->whereKey($chargebackRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ChargebackRequest::STATUS_PENDING_APPROVAL) {
                throw new DomainException('Only pending approval chargebacks can be approved.');
            }

            $locked->update([
                'reviewed_by_user_id' => $reviewedByUserId,
                'status' => ChargebackRequest::STATUS_QUEUED,
                'approved_at' => now(),
                'queued_at' => now(),
            ]);

            ProcessChargebackRequestJob::dispatch($locked->id);

            return $locked->refresh();
        });
    }

    public function rejectChargeback(ChargebackRequest $chargebackRequest, int $reviewedByUserId, string $rejectionReason): ChargebackRequest
    {
        return DB::transaction(function () use ($chargebackRequest, $reviewedByUserId, $rejectionReason): ChargebackRequest {
            $locked = ChargebackRequest::query()
                ->whereKey($chargebackRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ChargebackRequest::STATUS_PENDING_APPROVAL) {
                throw new DomainException('Only pending approval chargebacks can be rejected.');
            }

            $locked->update([
                'reviewed_by_user_id' => $reviewedByUserId,
                'status' => ChargebackRequest::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            return $locked->refresh();
        });
    }

    public function claimForProcessing(int $chargebackRequestId, string $jobUuid): ?ChargebackRequest
    {
        return DB::transaction(function () use ($chargebackRequestId, $jobUuid): ?ChargebackRequest {
            $chargebackRequest = ChargebackRequest::query()
                ->whereKey($chargebackRequestId)
                ->lockForUpdate()
                ->first();

            if (! $chargebackRequest instanceof ChargebackRequest) {
                return null;
            }

            $allowedStatuses = [
                ChargebackRequest::STATUS_QUEUED,
                ChargebackRequest::STATUS_PROCESSING,
                ChargebackRequest::STATUS_FAILED,
            ];

            if (! in_array($chargebackRequest->status, $allowedStatuses, true)) {
                return null;
            }

            if (
                $chargebackRequest->status === ChargebackRequest::STATUS_PROCESSING
                && $chargebackRequest->locked_at !== null
                && $chargebackRequest->locked_at->gt(now()->subMinutes(self::PROCESSING_LOCK_TIMEOUT_MINUTES))
            ) {
                return null;
            }

            $chargebackRequest->update([
                'status' => ChargebackRequest::STATUS_PROCESSING,
                'attempts_count' => (int) $chargebackRequest->attempts_count + 1,
                'last_attempt_at' => now(),
                'locked_at' => now(),
                'processing_job_uuid' => $jobUuid,
                'error_code' => null,
                'error_message' => null,
            ]);

            return $chargebackRequest->refresh();
        });
    }

    public function markCompleted(ChargebackRequest $chargebackRequest): ChargebackRequest
    {
        $chargebackRequest->update([
            'status' => ChargebackRequest::STATUS_COMPLETED,
            'processed_at' => now(),
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
            'locked_at' => null,
            'processing_job_uuid' => null,
        ]);

        return $chargebackRequest->refresh();
    }

    public function markFailed(ChargebackRequest $chargebackRequest, string $message, ?string $errorCode = null): ChargebackRequest
    {
        $chargebackRequest->update([
            'status' => ChargebackRequest::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $message,
            'failed_at' => now(),
            'locked_at' => null,
            'processing_job_uuid' => null,
        ]);

        return $chargebackRequest->refresh();
    }

    public function replay(ChargebackRequest $chargebackRequest): ChargebackRequest
    {
        return DB::transaction(function () use ($chargebackRequest): ChargebackRequest {
            $locked = ChargebackRequest::query()
                ->whereKey($chargebackRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [
                ChargebackRequest::STATUS_COMPLETED,
                ChargebackRequest::STATUS_REJECTED,
                ChargebackRequest::STATUS_PENDING_APPROVAL,
            ], true)) {
                throw new DomainException('Cannot replay a chargeback in this status.');
            }

            if (
                $locked->status === ChargebackRequest::STATUS_PROCESSING
                && $locked->locked_at !== null
                && $locked->locked_at->gt(now()->subMinutes(self::PROCESSING_LOCK_TIMEOUT_MINUTES))
            ) {
                throw new DomainException('Cannot replay a chargeback still being processed.');
            }

            if ($locked->replay_count >= 5) {
                throw new DomainException('Maximum replay limit reached.');
            }

            $locked->update([
                'status' => ChargebackRequest::STATUS_QUEUED,
                'queued_at' => now(),
                'locked_at' => null,
                'processing_job_uuid' => null,
                'error_code' => null,
                'error_message' => null,
                'replay_count' => (int) $locked->replay_count + 1,
            ]);

            ProcessChargebackRequestJob::dispatch($locked->id);

            return $locked->refresh();
        });
    }

    private function findExistingRequest(int $originalTransactionId, string $source, string $idempotencyKey): ?ChargebackRequest
    {
        return ChargebackRequest::query()
            ->where('original_transaction_id', $originalTransactionId)
            ->where('source', $source)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function supportsProviderApiChargeback(Transaction $transaction): bool
    {
        $provider = $transaction->paymentProvider;

        if (! $provider) {
            return false;
        }

        $code = strtolower((string) $provider->code);

        return in_array($code, ['firebank', 'woovi', 'cartwave', '7trust', 'seventrust'], true);
    }
}
