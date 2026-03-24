<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Enums;

enum WebhookProcessingStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
    case DeadLetter = 'dead_letter';

    public function canDispatch(): bool
    {
        return in_array($this, [self::Received, self::Failed], true);
    }

    public function isLocked(): bool
    {
        return in_array($this, [self::Processing, self::Processed, self::Ignored], true);
    }
}
