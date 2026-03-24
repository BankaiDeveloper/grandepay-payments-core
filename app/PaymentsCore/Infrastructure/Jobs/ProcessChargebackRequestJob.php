<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Jobs;

use App\PaymentsCore\Infrastructure\Models\ChargebackRequest;
use App\PaymentsCore\Infrastructure\Services\ChargebackRequestService;
use App\PaymentsCore\Infrastructure\Services\WalletOperationService;
use App\PaymentsCore\Domain\ValueObjects\NormalizedWebhookData;
use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\Queueable;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;

class ProcessChargebackRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        public readonly int $chargebackRequestId,
    ) {
        $this->onQueue(config('payment-queues.queues.high', 'payments-webhooks-high'));
    }

    public function handle(
        ChargebackRequestService $chargebackService,
        WalletOperationService $walletOperationService,
    ): void {
        $jobUuid = (string) Str::uuid();

        $chargebackRequest = $chargebackService->claimForProcessing($this->chargebackRequestId, $jobUuid);

        if (! $chargebackRequest instanceof ChargebackRequest) {
            return;
        }

        try {
            $transaction = $chargebackRequest->originalTransaction;

            if (! $transaction) {
                $chargebackService->markFailed($chargebackRequest, 'Original transaction not found', 'TRANSACTION_NOT_FOUND');
                return;
            }

            if ($chargebackRequest->execution_mode === ChargebackRequest::EXECUTION_INTERNAL_ADJUSTMENT) {
                $data = new NormalizedWebhookData(
                    providerTransactionId: $chargebackRequest->provider_reference,
                    endToEndId: $chargebackRequest->provider_end_to_end_id,
                    rawPayload: is_array($chargebackRequest->provider_response_payload) ? $chargebackRequest->provider_response_payload : [],
                );

                $walletOperationService->chargebackCashIn(
                    $transaction,
                    $chargebackRequest->webhookLog,
                    $chargebackRequest->paymentProvider?->code ?? 'unknown',
                    $data,
                );

                $chargebackService->markCompleted($chargebackRequest);

                Log::info('Chargeback processed (internal adjustment)', [
                    'chargeback_request_id' => $chargebackRequest->id,
                    'transaction_uuid' => $transaction->uuid,
                ]);

                return;
            }

            Log::info('Chargeback provider API call deferred (pending provider migration)', [
                'chargeback_request_id' => $chargebackRequest->id,
                'execution_mode' => $chargebackRequest->execution_mode,
            ]);

            $chargebackService->markFailed(
                $chargebackRequest,
                'Provider API chargeback not yet implemented in Hypervel',
                'PROVIDER_API_NOT_MIGRATED',
            );
        } catch (\Throwable $exception) {
            Log::error('Chargeback processing failed', [
                'chargeback_request_id' => $this->chargebackRequestId,
                'error' => $exception->getMessage(),
            ]);

            $chargebackService->markFailed($chargebackRequest, $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::critical('Chargeback processing job permanently failed', [
            'job_class' => static::class,
            'chargeback_request_id' => $this->chargebackRequestId,
            'error' => $exception?->getMessage(),
        ]);

        $chargebackRequest = ChargebackRequest::find($this->chargebackRequestId);

        if ($chargebackRequest instanceof ChargebackRequest) {
            $chargebackRequest->update([
                'status' => ChargebackRequest::STATUS_DEAD_LETTER,
                'locked_at' => null,
                'processing_job_uuid' => null,
            ]);
        }
    }
}
