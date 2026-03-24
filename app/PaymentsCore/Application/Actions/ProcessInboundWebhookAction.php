<?php

declare(strict_types=1);

namespace App\PaymentsCore\Application\Actions;

use App\PaymentsCore\Application\Handlers\CashInWebhookHandler;
use App\PaymentsCore\Application\Handlers\CashOutWebhookHandler;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use Hypervel\Support\Facades\Log;

final class ProcessInboundWebhookAction
{
    public function __construct(
        private readonly CashInWebhookHandler $cashInHandler,
        private readonly CashOutWebhookHandler $cashOutHandler,
    ) {}

    public function execute(WebhookLog $webhookLog): void
    {
        $providerCode = strtolower((string) $webhookLog->paymentProvider()->value('code'));
        $payload = is_array($webhookLog->payload) ? $webhookLog->payload : [];
        $event = $this->resolveEvent($providerCode, $payload);

        if ($event === null) {
            Log::warning('Webhook event could not be resolved', [
                'provider' => $providerCode,
                'webhook_log_id' => $webhookLog->id,
            ]);
            return;
        }

        match ($event) {
            'cash_in' => $this->cashInHandler->handle($webhookLog, $providerCode, $payload),
            'cash_out' => $this->cashOutHandler->handle($webhookLog, $providerCode, $payload),
            'chargeback' => $this->cashInHandler->handle($webhookLog, $providerCode, $payload),
            default => Log::warning('Unhandled webhook event type', [
                'event' => $event,
                'provider' => $providerCode,
            ]),
        };
    }

    private function resolveEvent(string $providerCode, array $payload): ?string
    {
        return match ($providerCode) {
            'firebank' => $this->resolveFirebankEvent($payload),
            'woovi' => $this->resolveWooviEvent($payload),
            'xflowpayments' => $this->resolveXFlowEvent($payload),
            'liberpay' => 'cash_in',
            'medusa' => 'cash_in',
            default => null,
        };
    }

    private function resolveFirebankEvent(array $payload): ?string
    {
        return match ($payload['event'] ?? null) {
            'CashIn' => 'cash_in',
            'CashOut' => 'cash_out',
            'CashInReversal' => 'chargeback',
            default => null,
        };
    }

    private function resolveWooviEvent(array $payload): ?string
    {
        if (($payload['charge']['status'] ?? null) === 'CHARGEBACK') {
            return 'chargeback';
        }
        if (isset($payload['charge'])) {
            return 'cash_in';
        }
        if (isset($payload['payment'])) {
            return 'cash_out';
        }
        return null;
    }

    private function resolveXFlowEvent(array $payload): ?string
    {
        $type = $payload['type'] ?? $payload['event_type'] ?? null;
        return match ($type) {
            'cash_in', 'pix_received', 'PAYMENT_RECEIVED' => 'cash_in',
            'cash_out' => 'cash_out',
            default => null,
        };
    }
}
