<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Jobs;

use App\PaymentsCore\Domain\Support\WithdrawalMode;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;
use App\PaymentsCore\Infrastructure\Services\WalletService;
use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\Queueable;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;

class ProcessWithdrawalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public array $backoff = [15, 30, 60, 120, 300];

    public function __construct(
        public readonly int $withdrawalId,
    ) {
        $this->onQueue(config('payment-queues.queues.medium', 'payments-withdrawals-medium'));
    }

    public function handle(): void
    {
        $withdrawal = Withdrawal::with([
            'enterprise',
            'paymentProvider',
            'transaction',
        ])->find($this->withdrawalId);

        if (! $withdrawal || $withdrawal->status !== Withdrawal::STATUS_PENDING) {
            return;
        }

        $withdrawal->update(['status' => Withdrawal::STATUS_PROCESSING]);

        Log::info('ProcessWithdrawalJob: withdrawal set to processing (provider call deferred to provider migration)', [
            'withdrawal_id' => $this->withdrawalId,
            'withdrawal_uuid' => $withdrawal->uuid,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::critical('Withdrawal processing job permanently failed', [
            'job_class' => static::class,
            'withdrawal_id' => $this->withdrawalId,
            'error' => $exception?->getMessage(),
        ]);

        $withdrawal = Withdrawal::with('paymentProvider')->find($this->withdrawalId);

        if ($withdrawal instanceof Withdrawal && ! in_array($withdrawal->status, [
            Withdrawal::STATUS_FAILED,
            Withdrawal::STATUS_CANCELLED,
            Withdrawal::STATUS_COMPLETED,
        ], true)) {
            $withdrawal->update([
                'status' => Withdrawal::STATUS_FAILED,
                'error_code' => 'JOB_PERMANENTLY_FAILED',
                'error_message' => Str::limit($exception?->getMessage() ?? 'Unknown error', 500),
                'failed_at' => now(),
            ]);

            $this->returnFunds($withdrawal);
        }
    }

    private function returnFunds(Withdrawal $withdrawal): void
    {
        $walletService = app(WalletService::class);

        if ($withdrawal->funds_state === WithdrawalMode::FUNDS_BLOCKED) {
            $walletService->unblock(
                enterpriseId: $withdrawal->enterprise_id,
                amountCents: $withdrawal->amount_cents,
                description: 'Withdrawal failed - reserved funds released',
                providerCode: $withdrawal->paymentProvider?->code,
            );
            return;
        }

        $walletService->credit(
            $withdrawal->enterprise_id,
            $withdrawal->amount_cents,
            $withdrawal->transaction,
            $withdrawal->paymentProvider?->code,
            'Withdrawal failed - funds returned',
        );
    }
}
