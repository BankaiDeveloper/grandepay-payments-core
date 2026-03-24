<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Support;

final class WithdrawalMode
{
    public const AUTOMATIC = 'automatic';
    public const MANUAL_APPROVAL = 'manual_approval';

    public const SOURCE_GATE_DEFAULT = 'gate_default';
    public const SOURCE_CUSTOM = 'custom';

    public const FUNDS_BLOCKED = 'blocked';
    public const FUNDS_DEBITED = 'debited';
    public const FUNDS_RELEASED = 'released';
    public const FUNDS_RETURNED = 'returned';

    public static function isValidMode(mixed $mode): bool
    {
        return in_array($mode, [self::AUTOMATIC, self::MANUAL_APPROVAL], true);
    }

    public static function normalizeMode(mixed $mode, mixed $legacyApprovalMode = null): string
    {
        if ($mode === self::AUTOMATIC || $mode === self::MANUAL_APPROVAL) {
            return $mode;
        }

        return self::fromLegacyApprovalMode($legacyApprovalMode);
    }

    public static function isValidSource(mixed $source): bool
    {
        return in_array($source, [self::SOURCE_GATE_DEFAULT, self::SOURCE_CUSTOM], true);
    }

    public static function normalizeSource(mixed $source): string
    {
        return self::isValidSource($source) ? $source : self::SOURCE_GATE_DEFAULT;
    }

    public static function fromLegacyApprovalMode(mixed $legacyApprovalMode): string
    {
        return match ($legacyApprovalMode) {
            'Aprovacao automatica' => self::AUTOMATIC,
            self::AUTOMATIC => self::AUTOMATIC,
            default => self::MANUAL_APPROVAL,
        };
    }
}
