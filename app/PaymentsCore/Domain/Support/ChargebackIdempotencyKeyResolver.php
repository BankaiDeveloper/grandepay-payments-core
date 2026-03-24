<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Support;

use App\PaymentsCore\Infrastructure\Models\Transaction;

final class ChargebackIdempotencyKeyResolver
{
    public static function resolve(
        ?string $explicitKey,
        string $source,
        Transaction $transaction,
        int $amountCents,
    ): string {
        if (is_string($explicitKey) && trim($explicitKey) !== '') {
            $trimmed = trim($explicitKey);
            if (mb_strlen($trimmed) >= 16 && mb_strlen($trimmed) <= 255 && preg_match('/^[a-zA-Z0-9\-_]+$/', $trimmed)) {
                return $trimmed;
            }
        }

        return hash('sha256', implode('|', [
            $source,
            $transaction->uuid,
            (string) $amountCents,
            'chargeback',
        ]));
    }
}
