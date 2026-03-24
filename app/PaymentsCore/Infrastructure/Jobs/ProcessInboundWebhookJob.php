<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Jobs;

use App\PaymentsCore\Application\Actions\ProcessInboundWebhookAction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Services\WebhookLogService;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\Dispatchable;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;

class ProcessInboundWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [10, 30, 60, 120, 300, 600];

    public function __construct(
        public readonly int $webhookLogId,
    ) {
        $this->onQueue(config('payment-queues.queues.high', 'payments-webhooks-high'));
    }

    public function handle(
        WebhookLogService $webhookLogService,
        ProcessInboundWebhookAction $action,
    ): void {
        $jobUuid = (string) Str::uuid();

        $webhookLog = $webhookLogService->claimForProcessing($this->webhookLogId, $jobUuid);

        if (! $webhookLog instanceof WebhookLog) {
            return;
        }

        try {
            $action->execute($webhookLog);
            $webhookLogService->markProcessed(
                $webhookLog,
                200,
                ['queued' => true, 'processed' => true],
            );
        } catch (\Throwable $exception) {
            Log::error('Inbound webhook job failed', [
                'webhook_log_id' => $this->webhookLogId,
                'job_uuid' => $jobUuid,
                'error' => $exception->getMessage(),
            ]);

            $webhookLogService->markFailed($webhookLog, $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::critical('Webhook processing job permanently failed', [
            'job_class' => static::class,
            'webhook_log_id' => $this->webhookLogId,
            'error' => $exception?->getMessage(),
        ]);

        $webhookLog = WebhookLog::find($this->webhookLogId);

        if ($webhookLog instanceof WebhookLog) {
            $webhookLog->update([
                'processing_status' => WebhookLog::STATUS_DEAD_LETTER,
            ]);
        }
    }
}
