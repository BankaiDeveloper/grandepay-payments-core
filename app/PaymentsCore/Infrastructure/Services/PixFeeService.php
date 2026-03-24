<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\GateSetting;
use App\PaymentsCore\Infrastructure\Models\Transaction;

final class PixFeeService
{
    private const SNAPSHOT_KEY = 'pix_fee_snapshot';

    /**
     * @return array{gross_amount_cents: int, fee_cents: int, net_amount_cents: int, rate_percent: float, flat_fee_cents: int, source: string}
     */
    public function calculateForEnterprise(Enterprise $enterprise, int $grossAmountCents): array
    {
        $grossAmountCents = max(0, $grossAmountCents);
        [$feeSettings, $source] = $this->resolveFeeSettings($enterprise);

        $ratePercent = max(0.0, (float) ($feeSettings['pix_tax'] ?? 0.0));
        $flatFeeCents = max(0, (int) round(((float) ($feeSettings['pix_flat'] ?? 0.0)) * 100));
        $percentageFeeCents = max(0, (int) round($grossAmountCents * ($ratePercent / 100)));
        $feeCents = min($grossAmountCents, $flatFeeCents + $percentageFeeCents);

        return $this->normalizeBreakdown(
            grossAmountCents: $grossAmountCents,
            feeCents: $feeCents,
            ratePercent: $ratePercent,
            flatFeeCents: $flatFeeCents,
            source: $source,
        );
    }

    /**
     * @return array{gross_amount_cents: int, fee_cents: int, net_amount_cents: int, rate_percent: float, flat_fee_cents: int, source: string}
     */
    public function calculateForTransaction(Transaction $transaction): array
    {
        $grossAmountCents = max(0, (int) $transaction->amount_cents);
        $snapshot = $this->extractSnapshot($transaction->metadata, $grossAmountCents);

        if ($snapshot !== null) {
            return $snapshot;
        }

        $enterprise = $transaction->relationLoaded('enterprise')
            ? $transaction->enterprise
            : $transaction->enterprise()->first();

        if ($enterprise instanceof Enterprise) {
            return $this->calculateForEnterprise($enterprise, $grossAmountCents);
        }

        return $this->normalizeBreakdown(
            grossAmountCents: $grossAmountCents,
            feeCents: (int) $transaction->fee_cents,
            ratePercent: 0.0,
            flatFeeCents: 0,
            source: 'stored_transaction',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function addSnapshotToMetadata(?array $metadata, array $breakdown): array
    {
        $normalizedMetadata = is_array($metadata) ? $metadata : [];
        $normalizedMetadata[self::SNAPSHOT_KEY] = [
            'version' => 1,
            'payment_method' => 'pix',
            'gross_amount_cents' => $breakdown['gross_amount_cents'],
            'fee_cents' => $breakdown['fee_cents'],
            'net_amount_cents' => $breakdown['net_amount_cents'],
            'rate_percent' => $breakdown['rate_percent'],
            'flat_fee_cents' => $breakdown['flat_fee_cents'],
            'source' => $breakdown['source'],
        ];

        return $normalizedMetadata;
    }

    /**
     * @return array{0: array<string, float>, 1: string}
     */
    private function resolveFeeSettings(Enterprise $enterprise): array
    {
        $settings = is_array($enterprise->settings) ? $enterprise->settings : [];
        $source = ($settings['enterprise_fees_source'] ?? null) === 'manual' ? 'manual' : 'gate_default';

        if ($source === 'manual') {
            $manualFees = $settings['enterprise_fees'] ?? null;
            if (is_array($manualFees) && isset($manualFees['pix_tax'])) {
                return [$manualFees, 'manual'];
            }
        }

        $gateSetting = GateSetting::query()->where('scope', 'default')->first();
        $gateDefaults = $gateSetting?->settings ?? [];

        return [$gateDefaults, 'gate_default'];
    }

    private function extractSnapshot(?array $metadata, int $grossAmountCents): ?array
    {
        if (! is_array($metadata)) {
            return null;
        }

        $snapshot = $metadata[self::SNAPSHOT_KEY] ?? null;

        if (! is_array($snapshot)) {
            return null;
        }

        return $this->normalizeBreakdown(
            grossAmountCents: (int) ($snapshot['gross_amount_cents'] ?? $grossAmountCents),
            feeCents: (int) ($snapshot['fee_cents'] ?? 0),
            ratePercent: (float) ($snapshot['rate_percent'] ?? 0.0),
            flatFeeCents: (int) ($snapshot['flat_fee_cents'] ?? 0),
            source: (string) ($snapshot['source'] ?? 'snapshot'),
        );
    }

    /**
     * @return array{gross_amount_cents: int, fee_cents: int, net_amount_cents: int, rate_percent: float, flat_fee_cents: int, source: string}
     */
    private function normalizeBreakdown(
        int $grossAmountCents,
        int $feeCents,
        float $ratePercent,
        int $flatFeeCents,
        string $source,
    ): array {
        $grossAmountCents = max(0, $grossAmountCents);
        $feeCents = max(0, min($grossAmountCents, $feeCents));

        return [
            'gross_amount_cents' => $grossAmountCents,
            'fee_cents' => $feeCents,
            'net_amount_cents' => max(0, $grossAmountCents - $feeCents),
            'rate_percent' => max(0.0, $ratePercent),
            'flat_fee_cents' => max(0, $flatFeeCents),
            'source' => $source,
        ];
    }
}
