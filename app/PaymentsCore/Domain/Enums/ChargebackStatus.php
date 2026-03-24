<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Enums;

enum ChargebackStatus: string
{
    case Requested = 'requested';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case DeadLetter = 'dead_letter';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::DeadLetter => 'Dead Letter',
            self::Rejected => 'Rejected',
        };
    }
}
