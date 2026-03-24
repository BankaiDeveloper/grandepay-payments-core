<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\PaymentsCore\Application\Actions\ProcessInboundWebhookAction;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Services\WebhookLogService;
use Hypervel\Console\Command;

class BenchmarkTestPipelineCommand extends Command
{
    protected ?string $signature = 'benchmark:test-pipeline {webhookLogId? : ID of webhook_log to process}';

    protected string $description = 'Test webhook processing pipeline directly (no queue)';

    public function handle(): int
    {
        $webhookLogId = $this->argument('webhookLogId');

        if (! $webhookLogId) {
            $webhookLog = WebhookLog::query()
                ->where('processing_status', WebhookLog::STATUS_RECEIVED)
                ->orderBy('id', 'desc')
                ->first();
        } else {
            $webhookLog = WebhookLog::find($webhookLogId);
        }

        if (! $webhookLog) {
            $this->error('No webhook log found');
            return 1;
        }

        $this->info("Processing webhook_log id={$webhookLog->id}, idempotency_key={$webhookLog->idempotency_key}");

        try {
            $webhookLogService = app(WebhookLogService::class);

            $this->info('Step 1: Claiming for processing...');
            $claimed = $webhookLogService->claimForProcessing($webhookLog->id, (string) \Hypervel\Support\Str::uuid());
            if (! $claimed) {
                $this->warn('Could not claim webhook (already processing/processed)');
                return 1;
            }
            $this->info("  Claimed. Status: {$claimed->processing_status}");

            $this->info('Step 2: Executing ProcessInboundWebhookAction...');
            $action = app(ProcessInboundWebhookAction::class);
            $action->execute($claimed);
            $this->info('  Action completed.');

            $this->info('Step 3: Marking as processed...');
            $webhookLogService->markProcessed($claimed, 200, ['test' => true]);
            $this->info('  Done.');

            $claimed->refresh();
            $this->info("Final status: {$claimed->processing_status}");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Pipeline failed: {$e->getMessage()}");
            $this->error("File: {$e->getFile()}:{$e->getLine()}");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
