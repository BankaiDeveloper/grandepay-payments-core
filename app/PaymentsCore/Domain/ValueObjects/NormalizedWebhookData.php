<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\ValueObjects;

readonly class NormalizedWebhookData
{
    public function __construct(
        public ?string $providerTransactionId = null,
        public ?string $endToEndId = null,
        public ?string $payerName = null,
        public ?string $payerDocument = null,
        public ?string $payerEmail = null,
        public ?int $webhookAmountCents = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawPayload = [],
    ) {}
}
