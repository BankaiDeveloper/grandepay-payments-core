<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Contracts;

use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;

interface PostbackServiceInterface
{
    public function notifyTransaction(string $event, Transaction $transaction): void;

    public function notifyWithdrawal(string $event, Withdrawal $withdrawal): void;
}
