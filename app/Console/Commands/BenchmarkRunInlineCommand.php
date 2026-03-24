<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\PaymentsCore\Application\Actions\ProcessInboundWebhookAction;
use App\PaymentsCore\Infrastructure\Jobs\SendSinglePostbackJob;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use App\PaymentsCore\Infrastructure\Services\WebhookLogService;
use Hypervel\Console\Command;

class BenchmarkRunInlineCommand extends Command
{
    protected ?string $signature = 'benchmark:process-inline {--limit=100 : Max webhooks to process}';

    protected string $description = 'Process pending webhooks + postbacks inline (no queue) for benchmark';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $webhookLogService = app(WebhookLogService::class);
        $action = app(ProcessInboundWebhookAction::class);

        $pending = WebhookLog::query()
            ->where('processing_status', WebhookLog::STATUS_RECEIVED)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("Processing {$pending->count()} webhooks inline...");

        $processed = 0;
        $failed = 0;
        $start = microtime(true);

        foreach ($pending as $webhookLog) {
            try {
                $claimed = $webhookLogService->claimForProcessing(
                    $webhookLog->id,
                    (string) \Hypervel\Support\Str::uuid(),
                );

                if (! $claimed) {
                    continue;
                }

                $action->execute($claimed);
                $webhookLogService->markProcessed($claimed, 200, ['inline' => true]);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  Webhook {$webhookLog->id}: {$e->getMessage()}");
            }
        }

        $elapsed = round(microtime(true) - $start, 2);
        $rate = $elapsed > 0 ? round($processed / $elapsed, 1) : 0;
        $this->info("Webhooks: {$processed} processed, {$failed} failed in {$elapsed}s ({$rate}/s)");

        // Now process postbacks
        $postbacks = PostbackLog::query()
            ->whereIn('status', [PostbackLog::STATUS_PENDING, PostbackLog::STATUS_FAILED])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("Sending {$postbacks->count()} postbacks inline...");

        $sent = 0;
        $postbackFailed = 0;
        $postbackStart = microtime(true);

        foreach ($postbacks as $postbackLog) {
            try {
                $job = new SendSinglePostbackJob($postbackLog->id);
                $job->handle();

                $postbackLog->refresh();
                if ($postbackLog->status === PostbackLog::STATUS_SENT) {
                    $sent++;
                } else {
                    $postbackFailed++;
                }
            } catch (\Throwable $e) {
                $postbackFailed++;
            }
        }

        $postbackElapsed = round(microtime(true) - $postbackStart, 2);
        $postbackRate = $postbackElapsed > 0 ? round($sent / $postbackElapsed, 1) : 0;
        $this->info("Postbacks: {$sent} sent, {$postbackFailed} failed in {$postbackElapsed}s ({$postbackRate}/s)");

        $totalElapsed = round(microtime(true) - $start, 2);
        $this->info("Total E2E: {$totalElapsed}s");

        return 0;
    }
}
