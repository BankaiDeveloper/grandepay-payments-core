<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Enums;

enum WithdrawalStatus: int
{
    case Pending = 0;
    case Processing = 1;
    case Completed = 2;
    case Failed = 3;
    case Cancelled = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
