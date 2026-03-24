<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Contracts;

use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;

interface AffiliateCommissionServiceInterface
{
    public function processCommission(Transaction $transaction, ?WebhookLog $webhookLog = null): void;

    public function reverseCommission(Transaction $transaction): void;
}
