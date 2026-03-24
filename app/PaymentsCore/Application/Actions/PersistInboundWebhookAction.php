<?php

declare(strict_types=1);

namespace App\PaymentsCore\Application\Actions;

use App\PaymentsCore\Infrastructure\Models\PaymentProvider;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Jobs\ProcessInboundWebhookJob;
use App\PaymentsCore\Infrastructure\Services\WebhookLogService;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Log;

final class PersistInboundWebhookAction
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_PROVIDER_MISSING = 'provider_missing';
    public const STATUS_INVALID_SIGNATURE = 'invalid_signature';

    public function __construct(
        private readonly WebhookLogService $webhookLogService,
    ) {}

    /**
     * @return array{status: string, provider: ?PaymentProvider, webhook_log: ?WebhookLog, error: ?string}
     */
    public function execute(
        Request $request,
        string $providerCode,
        ?string $eventType,
        string $idempotencyKey,
    ): array {
        $provider = Cache::remember(
            "payment_provider:code:{$providerCode}",
            300,
            fn () => PaymentProvider::query()->where('code', $providerCode)->first(),
        );

        if (! $provider instanceof PaymentProvider) {
            Log::error("{$providerCode} webhook received but provider not found in database");

            return [
                'status' => self::STATUS_PROVIDER_MISSING,
                'provider' => null,
                'webhook_log' => null,
                'error' => null,
            ];
        }

        $webhookLog = $this->webhookLogService->ingestIncoming(
            request: $request,
            paymentProviderId: $provider->id,
            idempotencyKey: $idempotencyKey,
            eventType: $eventType,
        );

        if ($webhookLog->processed) {
            Log::info("{$providerCode} webhook duplicate detected", ['idempotency_key' => $idempotencyKey]);

            return [
                'status' => self::STATUS_DUPLICATE,
                'provider' => $provider,
                'webhook_log' => $webhookLog,
                'error' => null,
            ];
        }

        if ($this->webhookLogService->shouldDispatchProcessing($webhookLog)) {
            ProcessInboundWebhookJob::dispatch($webhookLog->id);
        }

        return [
            'status' => self::STATUS_ACCEPTED,
            'provider' => $provider,
            'webhook_log' => $webhookLog,
            'error' => null,
        ];
    }
}
