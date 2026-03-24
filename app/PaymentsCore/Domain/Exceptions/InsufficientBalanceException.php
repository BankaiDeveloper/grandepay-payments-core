<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly int $availableCents,
        public readonly int $requestedCents,
    ) {
        parent::__construct(
            "Saldo insuficiente: disponivel {$availableCents} centavos, solicitado {$requestedCents} centavos."
        );
    }
}
