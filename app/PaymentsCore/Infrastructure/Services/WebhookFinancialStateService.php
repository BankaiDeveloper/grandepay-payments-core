<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\Contracts\AffiliateCommissionServiceInterface;
use App\PaymentsCore\Domain\Contracts\PostbackServiceInterface;
use App\PaymentsCore\Infrastructure\Models\GateSetting;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;
use App\PaymentsCore\Domain\Support\WithdrawalMode;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;

final class WebhookFinancialStateService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly PostbackServiceInterface $postbackService,
        private readonly AffiliateCommissionServiceInterface $affiliateCommissionService,
    ) {}

    public function lockTransaction(Transaction|int $transaction): Transaction
    {
        $transactionId = $transaction instanceof Transaction ? $transaction->id : $transaction;

        return Transaction::query()
            ->whereKey($transactionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function applyCashInPaid(
        Transaction $transaction,
        array $attributes,
        int $creditAmountCents,
        string $providerCode,
        string $description,
        ?WebhookLog $webhookLog = null,
        ?int $webhookAmountCents = null,
        bool $useTransaction = true,
    ): void {
        if (in_array($transaction->status, [
            Transaction::STATUS_PAID,
            Transaction::STATUS_REFUNDED,
            Transaction::STATUS_CHARGEBACK,
        ], true)) {
            return;
        }

        $this->validateWebhookAmount($transaction, $webhookAmountCents);

        $transaction->update(array_merge($attributes, [
            'status' => Transaction::STATUS_PAID,
            'paid_at' => now(),
        ]));

        $this->walletService->credit(
            $transaction->enterprise_id,
            $creditAmountCents,
            $transaction,
            $providerCode,
            $description,
            $webhookLog,
            $useTransaction,
        );

        $this->affiliateCommissionService->processCommission($transaction, $webhookLog);

        $this->postbackService->notifyTransaction('transaction.paid', $transaction);
    }

    public function applyCashInFailed(Transaction $transaction, array $attributes): void
    {
        DB::transaction(function () use ($transaction, $attributes): void {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (in_array($transaction->status, [
                Transaction::STATUS_PAID,
                Transaction::STATUS_FAILED,
                Transaction::STATUS_REFUNDED,
                Transaction::STATUS_CHARGEBACK,
            ], true)) {
                return;
            }

            $transaction->update(array_merge($attributes, [
                'status' => Transaction::STATUS_FAILED,
                'failed_at' => now(),
            ]));

            $this->postbackService->notifyTransaction('transaction.failed', $transaction);
        });
    }

    public function applyCashInCancelled(Transaction $transaction, array $attributes): void
    {
        DB::transaction(function () use ($transaction, $attributes): void {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (in_array($transaction->status, [
                Transaction::STATUS_PAID,
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_REFUNDED,
                Transaction::STATUS_CHARGEBACK,
            ], true)) {
                return;
            }

            $transaction->update(array_merge($attributes, [
                'status' => Transaction::STATUS_CANCELLED,
            ]));

            $this->postbackService->notifyTransaction('transaction.cancelled', $transaction);
        });
    }

    public function applyCashInPending(Transaction $transaction, array $attributes): void
    {
        DB::transaction(function () use ($transaction, $attributes): void {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (in_array($transaction->status, [
                Transaction::STATUS_PAID,
                Transaction::STATUS_FAILED,
                Transaction::STATUS_REFUNDED,
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_CHARGEBACK,
            ], true)) {
                return;
            }

            $transaction->update($attributes);
        });
    }

    public function applyCashInRefunded(
        Transaction $transaction,
        array $attributes,
        int $refundAmountCents,
        string $providerCode,
        string $description,
        ?WebhookLog $webhookLog = null,
        bool $useTransaction = true,
    ): void {
        if ($transaction->status === Transaction::STATUS_CHARGEBACK) {
            return;
        }

        $currentRefundedAmount = max(0, (int) ($transaction->refunded_amount_cents ?? 0));
        $targetRefundedAmount = min(
            (int) $transaction->amount_cents,
            $currentRefundedAmount + max(0, $refundAmountCents),
        );
        $deltaRefundAmount = $targetRefundedAmount - $currentRefundedAmount;

        if ($deltaRefundAmount <= 0) {
            return;
        }

        $isFullyRefunded = $targetRefundedAmount >= (int) $transaction->amount_cents;

        $transaction->update(array_merge($attributes, [
            'status' => $isFullyRefunded ? Transaction::STATUS_REFUNDED : Transaction::STATUS_PAID,
            'refunded_amount_cents' => $targetRefundedAmount,
            'refunded_at' => $isFullyRefunded ? now() : $transaction->refunded_at,
        ]));

        $this->walletService->refundDebit(
            $transaction->enterprise_id,
            $deltaRefundAmount,
            $transaction,
            $providerCode,
            $description,
            $webhookLog,
            $useTransaction,
        );

        if ($isFullyRefunded) {
            $this->affiliateCommissionService->reverseCommission($transaction);
        }

        $this->postbackService->notifyTransaction('transaction.refunded', $transaction);
    }

    public function applyCashInChargeback(
        Transaction $transaction,
        array $attributes,
        string $providerCode,
        string $description,
        ?WebhookLog $webhookLog = null,
        bool $useTransaction = true,
    ): void {
        if ($transaction->status === Transaction::STATUS_CHARGEBACK) {
            return;
        }

        $alreadyRefunded = max(0, (int) ($transaction->refunded_amount_cents ?? 0));
        $baseAmount = $this->resolveChargebackBaseAmount($transaction);
        $chargebackAmount = max(0, $baseAmount - $alreadyRefunded);

        $transaction->update(array_merge($attributes, [
            'status' => Transaction::STATUS_CHARGEBACK,
            'refunded_amount_cents' => $transaction->amount_cents,
        ]));

        if ($chargebackAmount > 0) {
            $this->walletService->refundDebit(
                $transaction->enterprise_id,
                $chargebackAmount,
                $transaction,
                $providerCode,
                $description,
                $webhookLog,
                $useTransaction,
            );
        }

        $this->affiliateCommissionService->reverseCommission($transaction);

        $this->postbackService->notifyTransaction('transaction.chargeback', $transaction);
    }

    public function lockWithdrawal(Withdrawal|int $withdrawal): Withdrawal
    {
        $withdrawalId = $withdrawal instanceof Withdrawal ? $withdrawal->id : $withdrawal;

        return Withdrawal::query()
            ->whereKey($withdrawalId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function applyCashOutCompleted(Withdrawal $withdrawal, array $attributes): void
    {
        if (in_array($withdrawal->status, [
            Withdrawal::STATUS_COMPLETED,
            Withdrawal::STATUS_FAILED,
            Withdrawal::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $withdrawal->update(array_merge($attributes, [
            'status' => Withdrawal::STATUS_COMPLETED,
            'completed_at' => now(),
            'metadata' => $this->withFundsState($withdrawal, WithdrawalMode::FUNDS_DEBITED),
        ]));

        $this->postbackService->notifyWithdrawal('withdrawal.completed', $withdrawal);
    }

    public function applyCashOutFailed(
        Withdrawal $withdrawal,
        array $attributes,
        string $providerCode,
        string $description,
        ?WebhookLog $webhookLog = null,
        bool $useTransaction = true,
    ): void {
        if (in_array($withdrawal->status, [
            Withdrawal::STATUS_COMPLETED,
            Withdrawal::STATUS_FAILED,
            Withdrawal::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $targetFundsState = $withdrawal->funds_state === WithdrawalMode::FUNDS_BLOCKED
            ? WithdrawalMode::FUNDS_RELEASED
            : WithdrawalMode::FUNDS_RETURNED;

        $withdrawal->update(array_merge($attributes, [
            'status' => Withdrawal::STATUS_FAILED,
            'failed_at' => now(),
            'metadata' => $this->withFundsState($withdrawal, $targetFundsState),
        ]));

        $this->reverseCashOutFunds($withdrawal, $providerCode, $description, $webhookLog, $useTransaction);

        $this->postbackService->notifyWithdrawal('withdrawal.failed', $withdrawal);
    }

    public function applyCashOutCancelled(
        Withdrawal $withdrawal,
        array $attributes,
        string $providerCode,
        string $description,
        ?WebhookLog $webhookLog = null,
        bool $useTransaction = true,
    ): void {
        if (in_array($withdrawal->status, [
            Withdrawal::STATUS_COMPLETED,
            Withdrawal::STATUS_FAILED,
            Withdrawal::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $targetFundsState = $withdrawal->funds_state === WithdrawalMode::FUNDS_BLOCKED
            ? WithdrawalMode::FUNDS_RELEASED
            : WithdrawalMode::FUNDS_RETURNED;

        $withdrawal->update(array_merge($attributes, [
            'status' => Withdrawal::STATUS_CANCELLED,
            'metadata' => $this->withFundsState($withdrawal, $targetFundsState),
        ]));

        $this->reverseCashOutFunds($withdrawal, $providerCode, $description, $webhookLog, $useTransaction);

        $this->postbackService->notifyWithdrawal('withdrawal.cancelled', $withdrawal);
    }

    public function syncCashOutState(Withdrawal $withdrawal, int $status, array $attributes): void
    {
        if (in_array($withdrawal->status, [
            Withdrawal::STATUS_COMPLETED,
            Withdrawal::STATUS_FAILED,
            Withdrawal::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $withdrawal->update(array_merge($attributes, [
            'status' => $status,
        ]));
    }

    private function validateWebhookAmount(Transaction $transaction, ?int $webhookAmountCents): void
    {
        if ($webhookAmountCents === null) {
            return;
        }

        $originalAmountCents = (int) $transaction->amount_cents;
        $difference = abs($webhookAmountCents - $originalAmountCents);
        $percentDifference = $originalAmountCents > 0
            ? ($difference / $originalAmountCents) * 100
            : ($difference > 0 ? 100.0 : 0.0);

        if ($difference > 100 || $percentDifference > 1.0) {
            Log::warning('Webhook amount mismatch detected', [
                'transaction_uuid' => $transaction->uuid,
                'transaction_id' => $transaction->id,
                'original_amount_cents' => $originalAmountCents,
                'webhook_amount_cents' => $webhookAmountCents,
                'difference_cents' => $difference,
                'difference_percent' => round($percentDifference, 2),
            ]);

            $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
            $metadata['amount_mismatch'] = true;
            $metadata['webhook_reported_amount_cents'] = $webhookAmountCents;
            $transaction->update(['metadata' => $metadata]);
        }
    }

    private function reverseCashOutFunds(
        Withdrawal $withdrawal,
        string $providerCode,
        string $description,
        ?WebhookLog $webhookLog = null,
        bool $useTransaction = true,
    ): void {
        if ($withdrawal->funds_state === WithdrawalMode::FUNDS_BLOCKED) {
            $this->walletService->unblock(
                enterpriseId: $withdrawal->enterprise_id,
                amountCents: $withdrawal->amount_cents,
                description: $description,
                providerCode: $providerCode,
                useTransaction: $useTransaction,
            );

            return;
        }

        $this->walletService->credit(
            $withdrawal->enterprise_id,
            $withdrawal->amount_cents,
            $withdrawal->transaction,
            $providerCode,
            $description,
            $webhookLog,
            $useTransaction,
        );
    }

    private function withFundsState(Withdrawal $withdrawal, string $fundsState): array
    {
        $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
        $metadata['funds_state'] = $fundsState;

        return $metadata;
    }

    private function resolveChargebackBaseAmount(Transaction $transaction): int
    {
        $gateSetting = GateSetting::query()->where('scope', 'default')->first();
        $mode = $gateSetting?->settings['chargebackDeductionMode'] ?? 'gross';

        if ($mode === 'net') {
            return (int) ($transaction->net_amount_cents ?? $transaction->amount_cents);
        }

        return (int) $transaction->amount_cents;
    }
}
