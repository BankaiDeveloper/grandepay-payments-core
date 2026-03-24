<?php

declare(strict_types=1);

namespace App\PaymentsCore\Http\Controllers;

use App\Http\Controllers\AbstractController;
use App\PaymentsCore\Infrastructure\Services\WebhookBufferService;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Log;

final class WebhookController extends AbstractController
{
    public function handle(Request $request, string $provider): array
    {
        try {
            $bufferService = app(WebhookBufferService::class);

            $rawBody = (string) $request->getBody();
            $payload = $request->all();

            $idempotencyKey = $request->header('Idempotency-Key')
                ?? $request->header('X-Idempotency-Key')
                ?? hash('sha256', $rawBody);

            $eventType = $payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? null;

            $bufferService->buffer(
                providerCode: $provider,
                idempotencyKey: $idempotencyKey,
                eventType: $eventType,
                rawBody: $rawBody,
                headers: $request->headers->all(),
                ipAddress: $request->ip() ?? '0.0.0.0',
                requestPath: $request->path() ?? '/api/webhooks/' . $provider,
                requestMethod: $request->method() ?? 'POST',
            );

            return ['acknowledged' => true];
        } catch (\Throwable $e) {
            Log::error('Webhook buffer error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Internal processing error'];
        }
    }
}
