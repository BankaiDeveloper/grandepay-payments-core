<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Enums;

enum TransactionType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case Refund = 'refund';
}
