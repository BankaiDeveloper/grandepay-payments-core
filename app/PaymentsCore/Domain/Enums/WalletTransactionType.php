<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Enums;

enum WalletTransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Block = 'block';
    case Unblock = 'unblock';
    case RefundDebit = 'refund_debit';
}
