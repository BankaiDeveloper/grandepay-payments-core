<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Exceptions;

use RuntimeException;

final class WithdrawalBlockedException extends RuntimeException
{
    public static function forEnterprise(int $enterpriseId): self
    {
        return new self(
            sprintf('Withdrawals are blocked for enterprise %d.', $enterpriseId),
        );
    }
}
