<?php

declare(strict_types=1);

namespace App\PaymentsCore\Http\Controllers;

use App\Http\Controllers\AbstractController;
use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Services\WithdrawalService;
use Hypervel\Http\Request;

final class WithdrawalController extends AbstractController
{
    public function __construct(
        private readonly WithdrawalService $withdrawalService,
    ) {}

    public function store(Request $request): array
    {
        $validated = $request->validate([
            'enterprise_id' => ['required', 'integer'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:50000000'],
            'pix_key' => ['required', 'string', 'min:1', 'max:255'],
            'pix_key_type' => ['required', 'string'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_document' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $enterprise = Enterprise::findOrFail($validated['enterprise_id']);

        $withdrawal = $this->withdrawalService->createWithdrawal(
            enterprise: $enterprise,
            data: $validated,
        );

        return [
            'uuid' => $withdrawal->uuid,
            'status' => $withdrawal->status,
            'amount_cents' => $withdrawal->amount_cents,
            'external_id' => $withdrawal->external_id,
            'processing_mode' => $withdrawal->processing_mode,
            'funds_state' => $withdrawal->funds_state,
        ];
    }

    public function show(string $uuid): array
    {
        $withdrawal = $this->withdrawalService->findByUuid($uuid);

        if (! $withdrawal) {
            return ['error' => 'Withdrawal not found'];
        }

        return [
            'uuid' => $withdrawal->uuid,
            'status' => $withdrawal->status,
            'amount_cents' => $withdrawal->amount_cents,
            'fee_cents' => $withdrawal->fee_cents,
            'net_amount_cents' => $withdrawal->net_amount_cents,
            'external_id' => $withdrawal->external_id,
            'pix_key' => $withdrawal->pix_key,
            'pix_key_type' => $withdrawal->pix_key_type,
            'recipient_name' => $withdrawal->recipient_name,
            'processing_mode' => $withdrawal->processing_mode,
            'funds_state' => $withdrawal->funds_state,
            'error_code' => $withdrawal->error_code,
            'error_message' => $withdrawal->error_message,
            'completed_at' => $withdrawal->completed_at?->toIso8601String(),
            'failed_at' => $withdrawal->failed_at?->toIso8601String(),
            'created_at' => $withdrawal->created_at?->toIso8601String(),
        ];
    }

    public function approve(Request $request, string $uuid): array
    {
        $withdrawal = $this->withdrawalService->findByUuid($uuid);

        if (! $withdrawal) {
            return ['error' => 'Withdrawal not found'];
        }

        $withdrawal = $this->withdrawalService->approveWithdrawal($withdrawal);

        return [
            'uuid' => $withdrawal->uuid,
            'status' => $withdrawal->status,
            'funds_state' => $withdrawal->funds_state,
        ];
    }

    public function reject(Request $request, string $uuid): array
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $withdrawal = $this->withdrawalService->findByUuid($uuid);

        if (! $withdrawal) {
            return ['error' => 'Withdrawal not found'];
        }

        $withdrawal = $this->withdrawalService->rejectWithdrawal(
            $withdrawal,
            $validated['reason'],
        );

        return [
            'uuid' => $withdrawal->uuid,
            'status' => $withdrawal->status,
            'funds_state' => $withdrawal->funds_state,
        ];
    }
}
