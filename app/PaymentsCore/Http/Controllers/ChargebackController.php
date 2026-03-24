<?php

declare(strict_types=1);

namespace App\PaymentsCore\Http\Controllers;

use App\Http\Controllers\AbstractController;
use App\PaymentsCore\Infrastructure\Models\ChargebackRequest;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Services\ChargebackRequestService;
use Hypervel\Http\Request;

final class ChargebackController extends AbstractController
{
    public function __construct(
        private readonly ChargebackRequestService $chargebackService,
    ) {}

    public function store(Request $request, string $transactionUuid): array
    {
        $validated = $request->validate([
            'reason_code' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'enterprise_id' => ['required', 'integer'],
        ]);

        $transaction = Transaction::where('uuid', $transactionUuid)->firstOrFail();

        $result = $this->chargebackService->requestManualChargeback(
            transaction: $transaction,
            requestedByUserId: $validated['enterprise_id'],
            source: ChargebackRequest::SOURCE_ENTERPRISE,
            reasonCode: $validated['reason_code'] ?? null,
            reason: $validated['reason'] ?? null,
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        $cb = $result['chargeback_request'];

        return [
            'uuid' => $cb->uuid,
            'status' => $cb->status,
            'amount_cents' => $cb->amount_cents,
            'replayed' => $result['replayed'],
        ];
    }

    public function show(int $id): array
    {
        $cb = ChargebackRequest::with(['originalTransaction', 'enterprise', 'paymentProvider'])->findOrFail($id);

        return [
            'id' => $cb->id,
            'uuid' => $cb->uuid,
            'status' => $cb->status,
            'source' => $cb->source,
            'execution_mode' => $cb->execution_mode,
            'amount_cents' => $cb->amount_cents,
            'reason_code' => $cb->reason_code,
            'reason' => $cb->reason,
            'error_code' => $cb->error_code,
            'error_message' => $cb->error_message,
            'attempts_count' => $cb->attempts_count,
            'replay_count' => $cb->replay_count,
            'approved_at' => $cb->approved_at?->toIso8601String(),
            'rejected_at' => $cb->rejected_at?->toIso8601String(),
            'processed_at' => $cb->processed_at?->toIso8601String(),
            'failed_at' => $cb->failed_at?->toIso8601String(),
            'created_at' => $cb->created_at?->toIso8601String(),
            'transaction_uuid' => $cb->originalTransaction?->uuid,
            'enterprise_id' => $cb->enterprise_id,
        ];
    }

    public function approve(Request $request, int $id): array
    {
        $validated = $request->validate([
            'reviewed_by_user_id' => ['required', 'integer'],
        ]);

        $cb = ChargebackRequest::findOrFail($id);
        $cb = $this->chargebackService->approveChargeback($cb, $validated['reviewed_by_user_id']);

        return [
            'uuid' => $cb->uuid,
            'status' => $cb->status,
        ];
    }

    public function reject(Request $request, int $id): array
    {
        $validated = $request->validate([
            'reviewed_by_user_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $cb = ChargebackRequest::findOrFail($id);
        $cb = $this->chargebackService->rejectChargeback($cb, $validated['reviewed_by_user_id'], $validated['reason']);

        return [
            'uuid' => $cb->uuid,
            'status' => $cb->status,
        ];
    }

    public function replay(int $id): array
    {
        $cb = ChargebackRequest::findOrFail($id);
        $cb = $this->chargebackService->replay($cb);

        return [
            'uuid' => $cb->uuid,
            'status' => $cb->status,
            'replay_count' => $cb->replay_count,
        ];
    }
}
