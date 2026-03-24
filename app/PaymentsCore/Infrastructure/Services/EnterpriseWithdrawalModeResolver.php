<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\Support\WithdrawalMode;
use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\GateSetting;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;

final class EnterpriseWithdrawalModeResolver
{
    public function resolveForEnterprise(Enterprise $enterprise): string
    {
        $settings = is_array($enterprise->settings) ? $enterprise->settings : [];
        $source = WithdrawalMode::normalizeSource($settings['withdrawal_mode_source'] ?? null);

        if ($source === WithdrawalMode::SOURCE_CUSTOM) {
            return WithdrawalMode::normalizeMode($settings['withdrawal_mode'] ?? null);
        }

        return $this->resolveGateDefault();
    }

    public function resolveForWithdrawal(Withdrawal $withdrawal): string
    {
        $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];

        if (WithdrawalMode::isValidMode($metadata['withdrawal_mode'] ?? null)) {
            return $metadata['withdrawal_mode'];
        }

        if ($withdrawal->relationLoaded('enterprise') && $withdrawal->enterprise instanceof Enterprise) {
            return $this->resolveForEnterprise($withdrawal->enterprise);
        }

        $enterprise = Enterprise::query()->find($withdrawal->enterprise_id);

        return $enterprise instanceof Enterprise
            ? $this->resolveForEnterprise($enterprise)
            : $this->resolveGateDefault();
    }

    public function resolveGateDefault(): string
    {
        $settings = GateSetting::query()
            ->where('scope', 'default')
            ->value('settings');

        return WithdrawalMode::normalizeMode(
            $settings['withdrawal_mode_default'] ?? null,
            $settings['tipoAprovacao'] ?? null,
        );
    }
}
