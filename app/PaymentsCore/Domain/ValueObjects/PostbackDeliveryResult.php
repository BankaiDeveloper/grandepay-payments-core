<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\ValueObjects;

final readonly class PostbackDeliveryResult
{
    public function __construct(
        public int $postbackLogId,
        public bool $sent,
        public string $status,
    ) {}
}
