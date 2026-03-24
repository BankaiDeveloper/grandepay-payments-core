<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\PaymentsCore\Infrastructure\Jobs\SendSinglePostbackJob;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use Hypervel\Console\Command;

class BenchmarkTestPostbackCommand extends Command
{
    protected ?string $signature = 'benchmark:test-postback {postbackLogId? : ID of postback_log to send}';

    protected string $description = 'Test postback delivery directly (no queue)';

    public function handle(): int
    {
        $id = $this->argument('postbackLogId');

        $postbackLog = $id
            ? PostbackLog::find($id)
            : PostbackLog::whereIn('status', [PostbackLog::STATUS_PENDING, PostbackLog::STATUS_FAILED])->first();

        if (! $postbackLog) {
            $this->error('No pending postback found');
            return 1;
        }

        $this->info("Sending postback id={$postbackLog->id} event={$postbackLog->event} url={$postbackLog->url} status={$postbackLog->status}");

        // Reset to pending if failed (for re-testing)
        if ($postbackLog->status === PostbackLog::STATUS_FAILED) {
            $postbackLog->update(['status' => PostbackLog::STATUS_PENDING, 'locked_at' => null, 'error_message' => null]);
            $this->warn('  Reset from failed to pending');
        }

        try {
            $this->info('Step 1: Claiming...');
            $job = new SendSinglePostbackJob($postbackLog->id);

            $this->info('Step 2: Calling handle()...');
            $job->handle();

            $postbackLog->refresh();
            $this->info("Result: status={$postbackLog->status} http_code={$postbackLog->http_status_code} error={$postbackLog->error_message}");

            return 0;
        } catch (\Throwable $e) {
            $postbackLog->refresh();
            $this->error("Failed: {$e->getMessage()}");
            $this->error("File: {$e->getFile()}:{$e->getLine()}");
            $this->error("DB status: {$postbackLog->status} error: {$postbackLog->error_message}");
            return 1;
        }
    }
}
