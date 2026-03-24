<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\ValueObjects;

readonly class WithdrawalRequest
{
    public function __construct(
        public int $amountCents,
        public string $pixKey,
        public string $pixKeyType = 'CPF',
        public ?string $externalId = null,
        public ?string $recipientName = null,
        public ?string $recipientDocument = null,
        public ?string $description = null,
        public array $metadata = [],
    ) {}
}
