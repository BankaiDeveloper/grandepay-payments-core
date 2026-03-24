<?php

declare(strict_types=1);

namespace App\PaymentsCore\Http\Controllers;

use App\Http\Controllers\AbstractController;
use App\PaymentsCore\Application\Actions\PersistInboundWebhookAction;
use Hypervel\Http\Request;
use Throwable;

final class WebhookController extends AbstractController
{
    public function handle(Request $request, string $provider): array
    {
        try {
            $persistAction = app(PersistInboundWebhookAction::class);

            $payload = $request->all();
            $rawBody = (string) $request->getBody();
            $idempotencyKey = $request->header('Idempotency-Key')
                ?? $request->header('X-Idempotency-Key')
                ?? hash('sha256', $rawBody);

            $eventType = $payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? null;

            $result = $persistAction->execute(
                request: $request,
                providerCode: $provider,
                eventType: $eventType,
                idempotencyKey: $idempotencyKey,
            );

            if ($result['status'] === PersistInboundWebhookAction::STATUS_PROVIDER_MISSING) {
                return ['error' => 'Provider not found'];
            }

            return ['acknowledged' => true];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()];
        }
    }
}
