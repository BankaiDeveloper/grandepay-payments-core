<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Stubs;

use App\PaymentsCore\Domain\Contracts\AffiliateCommissionServiceInterface;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use Hypervel\Support\Facades\Log;

class NullAffiliateCommissionService implements AffiliateCommissionServiceInterface
{
    public function processCommission(Transaction $transaction, ?WebhookLog $webhookLog = null): void
    {
        Log::debug('AffiliateCommission stub: commission not processed (pending migration)', [
            'transaction_uuid' => $transaction->uuid,
        ]);
    }

    public function reverseCommission(Transaction $transaction): void
    {
        Log::debug('AffiliateCommission stub: commission not reversed (pending migration)', [
            'transaction_uuid' => $transaction->uuid,
        ]);
    }
}
