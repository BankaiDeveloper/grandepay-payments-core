<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\ValueObjects\NormalizedWebhookData;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;

final class WalletOperationService
{
    public function __construct(
        private readonly WebhookFinancialStateService $financialStateService,
        private readonly PixFeeService $pixFeeService,
    ) {}

    public function creditCashIn(
        Transaction $transaction,
        WebhookLog $webhookLog,
        string $providerCode,
        NormalizedWebhookData $data,
    ): void {
        $feeBreakdown = $this->pixFeeService->calculateForTransaction($transaction);

        DB::transaction(function () use ($transaction, $webhookLog, $providerCode, $data, $feeBreakdown): void {
            $this->acquireAdvisoryLock($transaction->enterprise_id);

            $this->financialStateService->applyCashInPaid(
                transaction: $transaction,
                attributes: [
                    'fee_cents' => $feeBreakdown['fee_cents'],
                    'net_amount_cents' => $feeBreakdown['net_amount_cents'],
                    'provider_transaction_id' => $data->providerTransactionId ?? $transaction->provider_transaction_id,
                    'end_to_end_id' => $data->endToEndId ?? $transaction->end_to_end_id,
                    'payer_name' => $data->payerName ?? $transaction->payer_name,
                    'payer_document' => $data->payerDocument ?? $transaction->payer_document,
                    'provider_raw_webhook' => $data->rawPayload,
                    'metadata' => $this->pixFeeService->addSnapshotToMetadata(
                        $transaction->metadata,
                        $feeBreakdown,
                    ),
                ],
                creditAmountCents: $feeBreakdown['net_amount_cents'],
                providerCode: $providerCode,
                description: "PIX cash-in via {$providerCode}",
                webhookLog: $webhookLog,
                webhookAmountCents: $data->webhookAmountCents,
                useTransaction: false,
            );
        });

        Log::info('WalletOps: cash-in credited', [
            'transaction_uuid' => $transaction->uuid,
            'provider' => $providerCode,
            'net_amount_cents' => $feeBreakdown['net_amount_cents'],
        ]);
    }

    public function failCashIn(
        Transaction $transaction,
        NormalizedWebhookData $data,
    ): void {
        $this->financialStateService->applyCashInFailed($transaction, [
            'error_code' => $data->errorCode,
            'error_message' => $data->errorMessage,
            'provider_transaction_id' => $data->providerTransactionId ?? $transaction->provider_transaction_id,
            'provider_raw_webhook' => $data->rawPayload,
        ]);
    }

    public function cancelCashIn(
        Transaction $transaction,
        NormalizedWebhookData $data,
    ): void {
        $this->financialStateService->applyCashInCancelled($transaction, [
            'error_code' => $data->errorCode,
            'error_message' => $data->errorMessage,
            'provider_transaction_id' => $data->providerTransactionId ?? $transaction->provider_transaction_id,
            'provider_raw_webhook' => $data->rawPayload,
        ]);
    }

    public function refundCashIn(
        Transaction $transaction,
        WebhookLog $webhookLog,
        string $providerCode,
        NormalizedWebhookData $data,
        int $refundAmountCents,
    ): void {
        DB::transaction(function () use ($transaction, $webhookLog, $providerCode, $data, $refundAmountCents): void {
            $this->acquireAdvisoryLock($transaction->enterprise_id);

            $this->financialStateService->applyCashInRefunded(
                transaction: $transaction,
                attributes: [
                    'provider_transaction_id' => $data->providerTransactionId ?? $transaction->provider_transaction_id,
                    'provider_raw_webhook' => $data->rawPayload,
                ],
                refundAmountCents: $refundAmountCents,
                providerCode: $providerCode,
                description: "PIX refund via {$providerCode}",
                webhookLog: $webhookLog,
                useTransaction: false,
            );
        });

        Log::info('WalletOps: cash-in refunded', [
            'transaction_uuid' => $transaction->uuid,
            'provider' => $providerCode,
            'refund_amount_cents' => $refundAmountCents,
        ]);
    }

    public function chargebackCashIn(
        Transaction $transaction,
        ?WebhookLog $webhookLog,
        string $providerCode,
        NormalizedWebhookData $data,
    ): void {
        DB::transaction(function () use ($transaction, $webhookLog, $providerCode, $data): void {
            $this->acquireAdvisoryLock($transaction->enterprise_id);

            $this->financialStateService->applyCashInChargeback(
                transaction: $transaction,
                attributes: ['provider_raw_webhook' => $data->rawPayload],
                providerCode: $providerCode,
                description: "PIX chargeback via {$providerCode}",
                webhookLog: $webhookLog,
                useTransaction: false,
            );
        });

        Log::info('WalletOps: cash-in chargeback', [
            'transaction_uuid' => $transaction->uuid,
            'provider' => $providerCode,
        ]);
    }

    public function syncPendingCashIn(
        Transaction $transaction,
        NormalizedWebhookData $data,
    ): void {
        $this->financialStateService->applyCashInPending($transaction, [
            'provider_transaction_id' => $data->providerTransactionId ?? $transaction->provider_transaction_id,
            'end_to_end_id' => $data->endToEndId ?? $transaction->end_to_end_id,
            'provider_raw_webhook' => $data->rawPayload,
        ]);
    }

    public function completeCashOut(
        Withdrawal $withdrawal,
        NormalizedWebhookData $data,
    ): void {
        $this->financialStateService->applyCashOutCompleted($withdrawal, [
            'provider_withdrawal_id' => $data->providerTransactionId ?? $withdrawal->provider_withdrawal_id,
            'end_to_end_id' => $data->endToEndId ?? $withdrawal->end_to_end_id,
            'provider_raw_webhook' => $data->rawPayload,
        ]);
    }

    public function failCashOut(
        Withdrawal $withdrawal,
        WebhookLog $webhookLog,
        string $providerCode,
        NormalizedWebhookData $data,
    ): void {
        DB::transaction(function () use ($withdrawal, $webhookLog, $providerCode, $data): void {
            $this->acquireAdvisoryLock($withdrawal->enterprise_id);

            $this->financialStateService->applyCashOutFailed(
                withdrawal: $withdrawal,
                attributes: [
                    'provider_withdrawal_id' => $data->providerTransactionId ?? $withdrawal->provider_withdrawal_id,
                    'end_to_end_id' => $data->endToEndId ?? $withdrawal->end_to_end_id,
                    'error_code' => $data->errorCode,
                    'error_message' => $data->errorMessage,
                    'provider_raw_webhook' => $data->rawPayload,
                ],
                providerCode: $providerCode,
                description: "PIX cash-out failed via {$providerCode} - funds returned",
                webhookLog: $webhookLog,
                useTransaction: false,
            );
        });

        Log::info('WalletOps: cash-out failed', [
            'withdrawal_uuid' => $withdrawal->uuid,
            'provider' => $providerCode,
        ]);
    }

    public function cancelCashOut(
        Withdrawal $withdrawal,
        WebhookLog $webhookLog,
        string $providerCode,
        NormalizedWebhookData $data,
    ): void {
        DB::transaction(function () use ($withdrawal, $webhookLog, $providerCode, $data): void {
            $this->acquireAdvisoryLock($withdrawal->enterprise_id);

            $this->financialStateService->applyCashOutCancelled(
                withdrawal: $withdrawal,
                attributes: [
                    'provider_withdrawal_id' => $data->providerTransactionId ?? $withdrawal->provider_withdrawal_id,
                    'end_to_end_id' => $data->endToEndId ?? $withdrawal->end_to_end_id,
                    'error_code' => $data->errorCode,
                    'error_message' => $data->errorMessage,
                    'provider_raw_webhook' => $data->rawPayload,
                ],
                providerCode: $providerCode,
                description: "PIX cash-out cancelled via {$providerCode} - funds returned",
                webhookLog: $webhookLog,
                useTransaction: false,
            );
        });

        Log::info('WalletOps: cash-out cancelled', [
            'withdrawal_uuid' => $withdrawal->uuid,
            'provider' => $providerCode,
        ]);
    }

    public function syncCashOutState(
        Withdrawal $withdrawal,
        int $status,
        NormalizedWebhookData $data,
    ): void {
        $this->financialStateService->syncCashOutState($withdrawal, $status, [
            'provider_withdrawal_id' => $data->providerTransactionId ?? $withdrawal->provider_withdrawal_id,
            'end_to_end_id' => $data->endToEndId ?? $withdrawal->end_to_end_id,
            'provider_raw_webhook' => $data->rawPayload,
        ]);
    }

    private function acquireAdvisoryLock(int $enterpriseId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(?)', [$enterpriseId]);
    }
}
