<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\ValueObjects;

final readonly class PostbackDispatchResult
{
    public function __construct(
        public int $claimedCount,
        public int $sentCount,
        public int $failedCount,
    ) {}
}
