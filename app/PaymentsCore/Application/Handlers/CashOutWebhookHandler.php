<?php

declare(strict_types=1);

namespace App\PaymentsCore\Application\Handlers;

use App\PaymentsCore\Domain\ValueObjects\NormalizedWebhookData;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;
use App\PaymentsCore\Infrastructure\Services\ProviderWebhookMapper;
use App\PaymentsCore\Infrastructure\Services\WalletOperationService;
use App\PaymentsCore\Infrastructure\Services\WebhookFinancialStateService;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;

final class CashOutWebhookHandler
{
    public function __construct(
        private readonly WebhookFinancialStateService $financialStateService,
        private readonly WalletOperationService $walletOperationService,
        private readonly ProviderWebhookMapper $mapper,
    ) {}

    public function handle(WebhookLog $webhookLog, string $providerCode, array $payload): void
    {
        $status = $this->resolveStatus($providerCode, $payload);
        $withdrawal = $this->findWithdrawal($providerCode, $payload, $webhookLog->payment_provider_id);

        if (! $withdrawal) {
            Log::warning('CashOut webhook: withdrawal not found', [
                'provider' => $providerCode,
                'webhook_log_id' => $webhookLog->id,
            ]);
            return;
        }

        $webhookLog->update([
            'withdrawal_id' => $withdrawal->id,
            'enterprise_id' => $withdrawal->enterprise_id,
        ]);

        DB::transaction(function () use ($withdrawal, $providerCode, $payload, $status, $webhookLog): void {
            $withdrawal = $this->financialStateService->lockWithdrawal($withdrawal);

            match ($status) {
                'completed', 'confirmed', 'success' => $this->completeCashOut($withdrawal, $providerCode, $payload),
                'failed', 'error' => $this->failCashOut($withdrawal, $providerCode, $payload, $webhookLog),
                'cancelled' => $this->cancelCashOut($withdrawal, $providerCode, $payload, $webhookLog),
                'processing' => $this->syncState($withdrawal, Withdrawal::STATUS_PROCESSING, $providerCode, $payload),
                'pending' => $this->syncState($withdrawal, Withdrawal::STATUS_PENDING, $providerCode, $payload),
                default => Log::warning('CashOut unknown status', ['status' => $status, 'provider' => $providerCode]),
            };
        });
    }

    private function completeCashOut(Withdrawal $withdrawal, string $providerCode, array $payload): void
    {
        $data = $this->mapper->normalizeCashOut($providerCode, $payload);
        $this->walletOperationService->completeCashOut($withdrawal, $data);
    }

    private function failCashOut(Withdrawal $withdrawal, string $providerCode, array $payload, WebhookLog $webhookLog): void
    {
        $data = $this->mapper->normalizeCashOut($providerCode, $payload);
        $this->walletOperationService->failCashOut($withdrawal, $webhookLog, $providerCode, $data);
    }

    private function cancelCashOut(Withdrawal $withdrawal, string $providerCode, array $payload, WebhookLog $webhookLog): void
    {
        $data = $this->mapper->normalizeCashOut($providerCode, $payload);
        $this->walletOperationService->cancelCashOut($withdrawal, $webhookLog, $providerCode, $data);
    }

    private function syncState(Withdrawal $withdrawal, int $status, string $providerCode, array $payload): void
    {
        $data = $this->mapper->normalizeCashOut($providerCode, $payload);
        $this->walletOperationService->syncCashOutState($withdrawal, $status, $data);
    }

    private function resolveStatus(string $providerCode, array $payload): string
    {
        $rawStatus = strtolower((string) ($payload['status'] ?? ''));

        return match ($providerCode) {
            'firebank' => match ($rawStatus) {
                'confirmed' => 'completed',
                'error' => 'error',
                default => $rawStatus,
            },
            'woovi' => match (strtolower($payload['payment']['status'] ?? $rawStatus)) {
                'completed', 'confirmed' => 'completed',
                'failed' => 'failed',
                default => $rawStatus,
            },
            'xflowpayments' => match ($rawStatus) {
                'successful', 'confirmed', 'completed' => 'completed',
                'failed', 'failure', 'error' => 'failed',
                default => $rawStatus,
            },
            'liberpay' => match ($rawStatus) {
                'completed' => 'completed',
                'failed' => 'failed',
                default => $rawStatus,
            },
            'medusa' => match ($rawStatus) {
                'completed', 'paid' => 'completed',
                'failed' => 'failed',
                'cancelled' => 'cancelled',
                default => $rawStatus,
            },
            default => $rawStatus,
        };
    }

    private function findWithdrawal(string $providerCode, array $payload, int $providerId): ?Withdrawal
    {
        $externalId = $payload['externalId'] ?? $payload['external_id'] ?? null;
        $transactionId = $payload['transactionId'] ?? $payload['transaction_id'] ?? $payload['withdrawId'] ?? null;

        if ($providerCode === 'woovi') {
            $externalId = $payload['payment']['correlationID'] ?? $externalId;
        }

        if ($externalId) {
            $withdrawal = Withdrawal::where('external_id', $externalId)
                ->where('payment_provider_id', $providerId)
                ->first();

            if ($withdrawal) {
                return $withdrawal;
            }
        }

        if ($transactionId) {
            return Withdrawal::where('provider_withdrawal_id', $transactionId)
                ->where('payment_provider_id', $providerId)
                ->first();
        }

        return null;
    }
}
