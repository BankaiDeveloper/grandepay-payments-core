<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\Enums\TransactionType;
use App\PaymentsCore\Domain\Exceptions\InsufficientBalanceException;
use App\PaymentsCore\Domain\Exceptions\WithdrawalBlockedException;
use App\PaymentsCore\Domain\Support\WithdrawalMode;
use App\PaymentsCore\Infrastructure\Jobs\ProcessWithdrawalJob;
use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\Wallet;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;

class WithdrawalService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly EnterpriseWithdrawalModeResolver $withdrawalModeResolver,
    ) {}

    public function createWithdrawal(Enterprise $enterprise, array $data, ?int $initiatedByUserId = null): Withdrawal
    {
        $settings = is_array($enterprise->settings) ? $enterprise->settings : [];
        $adminControls = isset($settings['admin_controls']) && is_array($settings['admin_controls'])
            ? $settings['admin_controls']
            : [];

        if (! empty($adminControls['withdraw_blocked_at'])) {
            throw WithdrawalBlockedException::forEnterprise($enterprise->id);
        }

        $amountCents = (int) $data['amount_cents'];
        $withdrawalMode = $this->withdrawalModeResolver->resolveForEnterprise($enterprise);

        return DB::transaction(function () use ($enterprise, $data, $amountCents, $withdrawalMode, $initiatedByUserId): Withdrawal {
            $this->acquireAdvisoryLock($enterprise->id);

            $wallet = Wallet::where('enterprise_id', $enterprise->id)->lockForUpdate()->first();
            if (! $wallet || ($wallet->balance_cents - $wallet->blocked_cents) < $amountCents) {
                throw new InsufficientBalanceException(
                    availableCents: $wallet ? ($wallet->balance_cents - $wallet->blocked_cents) : 0,
                    requestedCents: $amountCents,
                );
            }

            $externalId = 'GP-' . (string) Str::uuid();
            $metadata = $this->withWithdrawalMetadata(
                $data['metadata'] ?? [],
                $withdrawalMode,
                $withdrawalMode === WithdrawalMode::MANUAL_APPROVAL
                    ? WithdrawalMode::FUNDS_BLOCKED
                    : WithdrawalMode::FUNDS_DEBITED,
            );

            $withdrawal = Withdrawal::create([
                'enterprise_id' => $enterprise->id,
                'status' => Withdrawal::STATUS_PENDING,
                'external_id' => $externalId,
                'amount_cents' => $amountCents,
                'fee_cents' => 0,
                'net_amount_cents' => $amountCents,
                'currency' => 'BRL',
                'pix_key' => $data['pix_key'] ?? null,
                'pix_key_type' => $data['pix_key_type'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_document' => $data['recipient_document'] ?? null,
                'description' => $data['description'] ?? null,
                'metadata' => $metadata,
            ]);

            $transaction = Transaction::create([
                'enterprise_id' => $enterprise->id,
                'type' => TransactionType::CashOut->value,
                'status' => Transaction::STATUS_PENDING,
                'external_id' => $externalId,
                'amount_cents' => $amountCents,
                'fee_cents' => 0,
                'net_amount_cents' => $amountCents,
                'currency' => 'BRL',
                'payment_method' => 'pix',
                'pix_key' => $data['pix_key'] ?? null,
                'pix_key_type' => $data['pix_key_type'] ?? null,
                'receiver_name' => $data['recipient_name'] ?? null,
                'receiver_document' => $data['recipient_document'] ?? null,
                'description' => $data['description'] ?? null,
                'metadata' => $metadata,
            ]);

            $withdrawal->update(['transaction_id' => $transaction->id]);

            if ($withdrawalMode === WithdrawalMode::MANUAL_APPROVAL) {
                $this->walletService->block(
                    enterpriseId: $enterprise->id,
                    amountCents: $amountCents,
                    transaction: $transaction,
                    description: 'Withdrawal funds reserved: ' . $externalId,
                    providerCode: null,
                    useTransaction: false,
                    initiatedByUserId: $initiatedByUserId,
                );
            } else {
                $this->walletService->debit(
                    enterpriseId: $enterprise->id,
                    amountCents: $amountCents,
                    providerCode: null,
                    description: 'Withdrawal debit: ' . $externalId,
                    useTransaction: false,
                    initiatedByUserId: $initiatedByUserId,
                );
            }

            Log::info('Withdrawal created', [
                'withdrawal_uuid' => $withdrawal->uuid,
                'transaction_uuid' => $transaction->uuid,
                'enterprise_id' => $enterprise->id,
                'amount_cents' => $amountCents,
                'withdrawal_mode' => $withdrawalMode,
            ]);

            if ($withdrawalMode === WithdrawalMode::AUTOMATIC) {
                ProcessWithdrawalJob::dispatch($withdrawal->id);
            }

            return $withdrawal->refresh();
        });
    }

    public function approveWithdrawal(Withdrawal $withdrawal, ?int $initiatedByUserId = null): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $initiatedByUserId): Withdrawal {
            $this->acquireAdvisoryLock($withdrawal->enterprise_id);

            $withdrawal = Withdrawal::query()
                ->with(['paymentProvider', 'transaction', 'enterprise'])
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== Withdrawal::STATUS_PENDING) {
                throw new \DomainException('Only pending withdrawals can be approved.');
            }

            if ($this->withdrawalModeResolver->resolveForWithdrawal($withdrawal) !== WithdrawalMode::MANUAL_APPROVAL) {
                throw new \DomainException('Automatic withdrawals do not require manual approval.');
            }

            $metadata = $this->normalizeMetadata($withdrawal->metadata);
            $fundsState = $metadata['funds_state'] ?? WithdrawalMode::FUNDS_BLOCKED;

            if ($fundsState !== WithdrawalMode::FUNDS_BLOCKED) {
                throw new \DomainException('This withdrawal is not awaiting reserved funds approval.');
            }

            $this->walletService->unblock(
                enterpriseId: $withdrawal->enterprise_id,
                amountCents: $withdrawal->amount_cents,
                description: 'Withdrawal approved - reserved funds released: ' . $withdrawal->uuid,
                providerCode: $withdrawal->paymentProvider?->code,
                useTransaction: false,
                initiatedByUserId: $initiatedByUserId,
            );

            $this->walletService->debit(
                enterpriseId: $withdrawal->enterprise_id,
                amountCents: $withdrawal->amount_cents,
                providerCode: $withdrawal->paymentProvider?->code,
                description: 'Withdrawal approved and debited: ' . $withdrawal->uuid,
                useTransaction: false,
                initiatedByUserId: $initiatedByUserId,
            );

            $metadata = $this->withWithdrawalMetadata($metadata, WithdrawalMode::MANUAL_APPROVAL, WithdrawalMode::FUNDS_DEBITED);
            $withdrawal->update(['metadata' => $metadata]);
            $withdrawal->transaction?->update(['metadata' => $metadata]);

            ProcessWithdrawalJob::dispatch($withdrawal->id);

            return $withdrawal->refresh();
        });
    }

    public function rejectWithdrawal(Withdrawal $withdrawal, string $reason, ?int $initiatedByUserId = null): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $reason, $initiatedByUserId): Withdrawal {
            $this->acquireAdvisoryLock($withdrawal->enterprise_id);

            $withdrawal = Withdrawal::query()
                ->with(['paymentProvider', 'transaction', 'enterprise'])
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== Withdrawal::STATUS_PENDING) {
                throw new \DomainException('Only pending withdrawals can be rejected.');
            }

            $metadata = $this->normalizeMetadata($withdrawal->metadata);
            $fundsState = $metadata['funds_state'] ?? WithdrawalMode::FUNDS_BLOCKED;

            if ($fundsState === WithdrawalMode::FUNDS_BLOCKED) {
                $this->walletService->unblock(
                    enterpriseId: $withdrawal->enterprise_id,
                    amountCents: $withdrawal->amount_cents,
                    description: 'Withdrawal rejected - reserved funds released: ' . $withdrawal->uuid,
                    providerCode: $withdrawal->paymentProvider?->code,
                    useTransaction: false,
                    initiatedByUserId: $initiatedByUserId,
                );
                $fundsState = WithdrawalMode::FUNDS_RELEASED;
            } elseif ($fundsState === WithdrawalMode::FUNDS_DEBITED) {
                $this->walletService->credit(
                    $withdrawal->enterprise_id,
                    $withdrawal->amount_cents,
                    $withdrawal->transaction,
                    $withdrawal->paymentProvider?->code,
                    'Withdrawal rejected after debit - funds returned: ' . $withdrawal->uuid,
                    useTransaction: false,
                    initiatedByUserId: $initiatedByUserId,
                );
                $fundsState = WithdrawalMode::FUNDS_RETURNED;
            }

            $metadata = $this->withWithdrawalMetadata($metadata, WithdrawalMode::MANUAL_APPROVAL, $fundsState);

            $withdrawal->update([
                'status' => Withdrawal::STATUS_CANCELLED,
                'error_message' => 'Admin rejected: ' . $reason,
                'metadata' => $metadata,
            ]);

            $withdrawal->transaction?->update([
                'status' => Transaction::STATUS_CANCELLED,
                'error_message' => 'Admin rejected withdrawal: ' . $reason,
                'metadata' => $metadata,
            ]);

            return $withdrawal->refresh();
        });
    }

    public function findByUuid(string $uuid, ?int $enterpriseId = null): ?Withdrawal
    {
        $query = Withdrawal::where('uuid', $uuid);

        if ($enterpriseId !== null) {
            $query->where('enterprise_id', $enterpriseId);
        }

        return $query->first();
    }

    private function acquireAdvisoryLock(int $enterpriseId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(?)', [$enterpriseId]);
    }

    private function normalizeMetadata(mixed $metadata): array
    {
        return is_array($metadata) ? $metadata : [];
    }

    private function withWithdrawalMetadata(mixed $metadata, string $mode, string $fundsState): array
    {
        $metadata = $this->normalizeMetadata($metadata);
        $metadata['withdrawal_mode'] = $mode;
        $metadata['funds_state'] = $fundsState;

        return $metadata;
    }
}
