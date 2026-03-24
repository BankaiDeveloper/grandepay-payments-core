<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Stubs;

use App\PaymentsCore\Domain\Contracts\PostbackServiceInterface;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;
use Hypervel\Support\Facades\Log;

class NullPostbackService implements PostbackServiceInterface
{
    public function notifyTransaction(string $event, Transaction $transaction): void
    {
        Log::debug('Postback stub: event not dispatched (pending migration)', [
            'event' => $event,
            'transaction_uuid' => $transaction->uuid,
        ]);
    }

    public function notifyWithdrawal(string $event, Withdrawal $withdrawal): void
    {
        Log::debug('Postback stub: withdrawal event not dispatched (pending migration)', [
            'event' => $event,
            'withdrawal_id' => $withdrawal->id,
        ]);
    }
}
