<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\Exceptions\InsufficientBalanceException;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\Wallet;
use App\PaymentsCore\Infrastructure\Models\WalletTransaction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use Hypervel\Database\QueryException;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;

class WalletService
{
    public function getOrCreateWallet(int $enterpriseId): Wallet
    {
        return Wallet::firstOrCreate(
            ['enterprise_id' => $enterpriseId],
            [
                'balance_cents' => 0,
                'blocked_cents' => 0,
                'currency' => 'BRL',
                'is_active' => true,
            ],
        );
    }

    public function credit(
        int $enterpriseId,
        int $amountCents,
        ?Transaction $transaction = null,
        ?string $providerCode = null,
        ?string $description = null,
        ?WebhookLog $webhookLog = null,
        bool $useTransaction = true,
        ?int $initiatedByUserId = null,
    ): WalletTransaction {
        $operation = function () use ($enterpriseId, $amountCents, $transaction, $providerCode, $description, $webhookLog, $initiatedByUserId): WalletTransaction {
            if ($webhookLog instanceof WebhookLog) {
                $existing = WalletTransaction::query()
                    ->where('webhook_log_id', $webhookLog->id)
                    ->first();

                if ($existing instanceof WalletTransaction) {
                    return $existing;
                }
            }

            $wallet = $this->getOrCreateWallet($enterpriseId);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $balanceBefore = $wallet->balance_cents;
            $balanceAfter = $balanceBefore + $amountCents;

            $wallet->update(['balance_cents' => $balanceAfter]);

            $walletTransaction = WalletTransaction::create([
                'uuid' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'enterprise_id' => $enterpriseId,
                'transaction_id' => $transaction?->id,
                'webhook_log_id' => $webhookLog?->id,
                'type' => 'credit',
                'amount_cents' => $amountCents,
                'balance_before_cents' => $balanceBefore,
                'balance_after_cents' => $balanceAfter,
                'description' => $description ?? 'Wallet credit',
                'provider_code' => $providerCode,
                'initiated_by_user_id' => $initiatedByUserId,
                'metadata' => $webhookLog instanceof WebhookLog
                    ? [
                        'source' => 'provider_webhook',
                        'webhook_log_id' => $webhookLog->id,
                    ]
                    : null,
            ]);

            Log::info('Wallet credited', [
                'enterprise_id' => $enterpriseId,
                'amount_cents' => $amountCents,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'wallet_transaction_uuid' => $walletTransaction->uuid,
                'webhook_log_id' => $webhookLog?->id,
            ]);

            return $walletTransaction;
        };

        try {
            return $useTransaction ? DB::transaction($operation) : $operation();
        } catch (QueryException $exception) {
            if (! ($webhookLog instanceof WebhookLog) || ! $this->isWebhookLogUniqueViolation($exception)) {
                throw $exception;
            }

            return WalletTransaction::query()
                ->where('webhook_log_id', $webhookLog->id)
                ->firstOrFail();
        }
    }

    public function debit(
        int $enterpriseId,
        int $amountCents,
        ?string $providerCode = null,
        ?string $description = null,
        bool $useTransaction = true,
        ?int $initiatedByUserId = null,
    ): WalletTransaction {
        $operation = function () use ($enterpriseId, $amountCents, $providerCode, $description, $initiatedByUserId): WalletTransaction {
            $wallet = $this->getOrCreateWallet($enterpriseId);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $availableCents = $wallet->balance_cents - $wallet->blocked_cents;

            if ($availableCents < $amountCents) {
                throw new InsufficientBalanceException(
                    availableCents: $availableCents,
                    requestedCents: $amountCents,
                );
            }

            $balanceBefore = $wallet->balance_cents;
            $balanceAfter = $balanceBefore - $amountCents;

            $wallet->update(['balance_cents' => $balanceAfter]);

            $walletTransaction = WalletTransaction::create([
                'uuid' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'enterprise_id' => $enterpriseId,
                'type' => 'debit',
                'amount_cents' => $amountCents,
                'balance_before_cents' => $balanceBefore,
                'balance_after_cents' => $balanceAfter,
                'description' => $description ?? 'Wallet debit',
                'provider_code' => $providerCode,
                'initiated_by_user_id' => $initiatedByUserId,
            ]);

            Log::info('Wallet debited', [
                'enterprise_id' => $enterpriseId,
                'amount_cents' => $amountCents,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'wallet_transaction_uuid' => $walletTransaction->uuid,
            ]);

            return $walletTransaction;
        };

        return $useTransaction ? DB::transaction($operation) : $operation();
    }

    public function refundDebit(
        int $enterpriseId,
        int $amountCents,
        ?Transaction $transaction = null,
        ?string $providerCode = null,
        ?string $description = null,
        ?WebhookLog $webhookLog = null,
        bool $useTransaction = true,
        ?int $initiatedByUserId = null,
    ): WalletTransaction {
        $operation = function () use ($enterpriseId, $amountCents, $transaction, $providerCode, $description, $webhookLog, $initiatedByUserId): WalletTransaction {
            if ($webhookLog instanceof WebhookLog) {
                $existing = WalletTransaction::query()
                    ->where('webhook_log_id', $webhookLog->id)
                    ->first();

                if ($existing instanceof WalletTransaction) {
                    return $existing;
                }
            }

            $wallet = $this->getOrCreateWallet($enterpriseId);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $balanceBefore = $wallet->balance_cents;
            $balanceAfter = $balanceBefore - $amountCents;

            if ($balanceAfter < 0) {
                Log::warning('Webhook refund debit will result in negative balance', [
                    'enterprise_id' => $enterpriseId,
                    'amount_cents' => $amountCents,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'webhook_log_id' => $webhookLog?->id,
                ]);
            }

            $wallet->update(['balance_cents' => $balanceAfter]);

            $walletTransaction = WalletTransaction::create([
                'uuid' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'enterprise_id' => $enterpriseId,
                'transaction_id' => $transaction?->id,
                'webhook_log_id' => $webhookLog?->id,
                'type' => 'refund_debit',
                'amount_cents' => $amountCents,
                'balance_before_cents' => $balanceBefore,
                'balance_after_cents' => $balanceAfter,
                'description' => $description ?? 'Webhook refund debit',
                'provider_code' => $providerCode,
                'initiated_by_user_id' => $initiatedByUserId,
                'metadata' => $webhookLog instanceof WebhookLog
                    ? [
                        'source' => 'provider_webhook_refund',
                        'webhook_log_id' => $webhookLog->id,
                    ]
                    : null,
            ]);

            Log::info('Wallet refund debited', [
                'enterprise_id' => $enterpriseId,
                'amount_cents' => $amountCents,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'wallet_transaction_uuid' => $walletTransaction->uuid,
                'webhook_log_id' => $webhookLog?->id,
            ]);

            return $walletTransaction;
        };

        try {
            return $useTransaction ? DB::transaction($operation) : $operation();
        } catch (QueryException $exception) {
            if (! ($webhookLog instanceof WebhookLog) || ! $this->isWebhookLogUniqueViolation($exception)) {
                throw $exception;
            }

            return WalletTransaction::query()
                ->where('webhook_log_id', $webhookLog->id)
                ->firstOrFail();
        }
    }

    public function getBalance(int $enterpriseId): array
    {
        $wallet = $this->getOrCreateWallet($enterpriseId);

        return [
            'balance_cents' => $wallet->balance_cents,
            'blocked_cents' => $wallet->blocked_cents,
            'available_cents' => $wallet->balance_cents - $wallet->blocked_cents,
        ];
    }

    public function block(
        int $enterpriseId,
        int $amountCents,
        ?Transaction $transaction = null,
        ?string $description = null,
        ?WebhookLog $webhookLog = null,
        ?string $providerCode = null,
        bool $useTransaction = true,
        ?int $initiatedByUserId = null,
    ): WalletTransaction {
        $operation = function () use ($enterpriseId, $amountCents, $transaction, $description, $webhookLog, $providerCode, $initiatedByUserId): WalletTransaction {
            if ($webhookLog instanceof WebhookLog) {
                $existing = WalletTransaction::query()
                    ->where('webhook_log_id', $webhookLog->id)
                    ->first();

                if ($existing instanceof WalletTransaction) {
                    return $existing;
                }
            }

            $wallet = $this->getOrCreateWallet($enterpriseId);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $blockedBefore = $wallet->blocked_cents;
            $blockedAfter = $blockedBefore + $amountCents;

            $wallet->update(['blocked_cents' => $blockedAfter]);

            $walletTransaction = WalletTransaction::create([
                'uuid' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'enterprise_id' => $enterpriseId,
                'transaction_id' => $transaction?->id,
                'webhook_log_id' => $webhookLog?->id,
                'type' => 'block',
                'amount_cents' => $amountCents,
                'balance_before_cents' => $wallet->balance_cents,
                'balance_after_cents' => $wallet->balance_cents,
                'description' => $description ?? 'Funds blocked',
                'provider_code' => $providerCode,
                'initiated_by_user_id' => $initiatedByUserId,
                'metadata' => $webhookLog instanceof WebhookLog
                    ? ['source' => 'provider_webhook', 'webhook_log_id' => $webhookLog->id]
                    : null,
            ]);

            Log::info('Wallet funds blocked', [
                'enterprise_id' => $enterpriseId,
                'amount_cents' => $amountCents,
                'blocked_before' => $blockedBefore,
                'blocked_after' => $blockedAfter,
                'wallet_transaction_uuid' => $walletTransaction->uuid,
            ]);

            return $walletTransaction;
        };

        try {
            return $useTransaction ? DB::transaction($operation) : $operation();
        } catch (QueryException $exception) {
            if (! ($webhookLog instanceof WebhookLog) || ! $this->isWebhookLogUniqueViolation($exception)) {
                throw $exception;
            }

            return WalletTransaction::query()
                ->where('webhook_log_id', $webhookLog->id)
                ->firstOrFail();
        }
    }

    public function unblock(
        int $enterpriseId,
        int $amountCents,
        ?string $description = null,
        ?string $providerCode = null,
        bool $useTransaction = true,
        ?int $initiatedByUserId = null,
    ): WalletTransaction {
        $operation = function () use ($enterpriseId, $amountCents, $description, $providerCode, $initiatedByUserId): WalletTransaction {
            $wallet = $this->getOrCreateWallet($enterpriseId);
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $blockedBefore = $wallet->blocked_cents;
            $blockedAfter = $blockedBefore - $amountCents;

            if ($blockedAfter < 0) {
                Log::warning('Unblock amount exceeds blocked balance, capping to zero', [
                    'enterprise_id' => $enterpriseId,
                    'blocked_before' => $blockedBefore,
                    'unblock_amount' => $amountCents,
                ]);
                $blockedAfter = 0;
            }

            $wallet->update(['blocked_cents' => $blockedAfter]);

            $walletTransaction = WalletTransaction::create([
                'uuid' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'enterprise_id' => $enterpriseId,
                'type' => 'unblock',
                'amount_cents' => $amountCents,
                'balance_before_cents' => $wallet->balance_cents,
                'balance_after_cents' => $wallet->balance_cents,
                'description' => $description ?? 'Funds unblocked',
                'provider_code' => $providerCode,
                'initiated_by_user_id' => $initiatedByUserId,
            ]);

            Log::info('Wallet funds unblocked', [
                'enterprise_id' => $enterpriseId,
                'amount_cents' => $amountCents,
                'blocked_before' => $blockedBefore,
                'blocked_after' => $blockedAfter,
                'wallet_transaction_uuid' => $walletTransaction->uuid,
            ]);

            return $walletTransaction;
        };

        return $useTransaction ? DB::transaction($operation) : $operation();
    }

    private function isWebhookLogUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $sqlState = (string) $exception->getCode();

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($message, 'wallet_transactions_webhook_log_id_unique');
    }
}
