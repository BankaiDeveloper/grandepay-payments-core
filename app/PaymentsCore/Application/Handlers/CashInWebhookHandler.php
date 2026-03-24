<?php

declare(strict_types=1);

namespace App\PaymentsCore\Application\Handlers;

use App\PaymentsCore\Domain\ValueObjects\NormalizedWebhookData;
use App\PaymentsCore\Infrastructure\Models\ChargebackRequest;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Services\ProviderWebhookMapper;
use App\PaymentsCore\Infrastructure\Services\ChargebackRequestService;
use App\PaymentsCore\Infrastructure\Services\WalletOperationService;
use App\PaymentsCore\Infrastructure\Services\WebhookFinancialStateService;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;

final class CashInWebhookHandler
{
    public function __construct(
        private readonly WebhookFinancialStateService $financialStateService,
        private readonly WalletOperationService $walletOperationService,
        private readonly ProviderWebhookMapper $mapper,
        private readonly ChargebackRequestService $chargebackService,
    ) {}

    public function handle(WebhookLog $webhookLog, string $providerCode, array $payload): void
    {
        $status = $this->resolveStatus($providerCode, $payload);
        $transaction = $this->findTransaction($providerCode, $payload, $webhookLog->payment_provider_id);

        if (! $transaction) {
            Log::warning("CashIn webhook: transaction not found", [
                'provider' => $providerCode,
                'webhook_log_id' => $webhookLog->id,
            ]);
            return;
        }

        $webhookLog->update([
            'transaction_id' => $transaction->id,
            'enterprise_id' => $transaction->enterprise_id,
        ]);

        DB::transaction(function () use ($transaction, $providerCode, $payload, $status, $webhookLog): void {
            $transaction = $this->financialStateService->lockTransaction($transaction);

            match ($status) {
                'confirmed', 'paid', 'completed' => $this->confirmCashIn($transaction, $providerCode, $payload, $webhookLog),
                'failed', 'error' => $this->failCashIn($transaction, $providerCode, $payload),
                'cancelled', 'expired' => $this->cancelCashIn($transaction, $providerCode, $payload),
                'chargeback' => $this->chargebackCashIn($transaction, $providerCode, $payload, $webhookLog),
                default => Log::warning("CashIn unknown status", ['status' => $status, 'provider' => $providerCode]),
            };
        });
    }

    private function confirmCashIn(Transaction $transaction, string $providerCode, array $payload, WebhookLog $webhookLog): void
    {
        $data = $this->mapper->normalizeCashIn($providerCode, $payload);
        $this->walletOperationService->creditCashIn($transaction, $webhookLog, $providerCode, $data);
    }

    private function failCashIn(Transaction $transaction, string $providerCode, array $payload): void
    {
        $data = new NormalizedWebhookData(
            providerTransactionId: $payload['transactionId'] ?? $payload['transaction_id'] ?? null,
            errorCode: $payload['errorCode'] ?? $payload['error_code'] ?? null,
            errorMessage: $payload['errorMessage'] ?? $payload['error_message'] ?? null,
            rawPayload: $payload,
        );

        $this->walletOperationService->failCashIn($transaction, $data);
    }

    private function cancelCashIn(Transaction $transaction, string $providerCode, array $payload): void
    {
        $data = new NormalizedWebhookData(
            providerTransactionId: $payload['transactionId'] ?? $payload['transaction_id'] ?? null,
            errorCode: $payload['errorCode'] ?? $payload['status'] ?? null,
            rawPayload: $payload,
        );

        $this->walletOperationService->cancelCashIn($transaction, $data);
    }

    private function chargebackCashIn(Transaction $transaction, string $providerCode, array $payload, WebhookLog $webhookLog): void
    {
        $data = new NormalizedWebhookData(
            providerTransactionId: $payload['transactionId'] ?? $payload['transaction_id'] ?? null,
            endToEndId: $payload['endToEndId'] ?? $payload['end_to_end_id'] ?? null,
            rawPayload: $payload,
        );

        $chargebackRequest = $this->chargebackService->processWebhookChargeback(
            $transaction,
            $webhookLog,
            $providerCode,
            $data,
        );

        if (! $chargebackRequest->wasRecentlyCreated) {
            return;
        }

        $this->walletOperationService->chargebackCashIn($transaction, $webhookLog, $providerCode, $data);
    }

    private function resolveStatus(string $providerCode, array $payload): string
    {
        $rawStatus = strtolower((string) ($payload['status'] ?? ''));

        return match ($providerCode) {
            'firebank' => match ($rawStatus) {
                'confirmed' => 'confirmed',
                'error' => 'error',
                default => $rawStatus,
            },
            'woovi' => match (strtolower($payload['charge']['status'] ?? '')) {
                'completed' => 'completed',
                'expired' => 'expired',
                'chargeback' => 'chargeback',
                default => $rawStatus,
            },
            'xflowpayments' => match ($rawStatus) {
                'confirmed', 'completed', 'paid' => 'confirmed',
                'error', 'failed' => 'failed',
                'chargeback' => 'chargeback',
                default => $rawStatus,
            },
            'liberpay' => match ($rawStatus) {
                'paid', 'confirmed', 'approved' => 'confirmed',
                'expired', 'cancelled' => 'cancelled',
                default => $rawStatus,
            },
            'medusa' => match ($rawStatus) {
                'paid', 'confirmed', 'completed' => 'confirmed',
                'failed', 'error' => 'failed',
                'chargeback' => 'chargeback',
                default => $rawStatus,
            },
            default => $rawStatus,
        };
    }

    private function findTransaction(string $providerCode, array $payload, int $providerId): ?Transaction
    {
        $externalId = $payload['externalId'] ?? $payload['external_id'] ?? $payload['correlationID'] ?? null;
        $transactionId = $payload['transactionId'] ?? $payload['transaction_id'] ?? null;

        if ($providerCode === 'woovi') {
            $externalId = $payload['charge']['correlationID'] ?? $externalId;
            $transactionId = $payload['charge']['transactionID'] ?? $transactionId;
        }

        if ($externalId) {
            $transaction = Transaction::where('external_id', $externalId)
                ->where('payment_provider_id', $providerId)
                ->first();

            if ($transaction) {
                return $transaction;
            }
        }

        if ($transactionId) {
            return Transaction::where('provider_transaction_id', $transactionId)
                ->where('payment_provider_id', $providerId)
                ->first();
        }

        return null;
    }
}
