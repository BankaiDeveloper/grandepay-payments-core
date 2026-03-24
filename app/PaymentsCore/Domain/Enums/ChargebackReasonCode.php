<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Enums;

enum ChargebackReasonCode: string
{
    case Fraud = 'fraud';
    case Duplicate = 'duplicate';
    case ProductNotReceived = 'product_not_received';
    case ProductNotAsDescribed = 'product_not_as_described';
    case ProcessingError = 'processing_error';
    case Unauthorized = 'unauthorized';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fraud => 'Fraud',
            self::Duplicate => 'Duplicate',
            self::ProductNotReceived => 'Product Not Received',
            self::ProductNotAsDescribed => 'Product Not as Described',
            self::ProcessingError => 'Processing Error',
            self::Unauthorized => 'Unauthorized',
            self::Other => 'Other',
        };
    }
}
