<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Enums;

enum TransactionStatus: int
{
    case Pending = 0;
    case Paid = 2;
    case Failed = 3;
    case Refunded = 4;
    case Cancelled = 5;
    case Chargeback = 6;

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
            self::Chargeback => 'Chargeback',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Refunded, self::Cancelled, self::Chargeback], true);
    }
}
