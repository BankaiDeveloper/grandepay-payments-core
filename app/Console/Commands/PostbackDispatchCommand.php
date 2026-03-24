<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\PaymentsCore\Infrastructure\Services\PostbackDispatcherService;
use Hypervel\Console\Command;

final class PostbackDispatchCommand extends Command
{
    protected ?string $signature = 'postbacks:dispatch
        {--once : Process a single batch and exit}
        {--batch=0 : Number of postbacks to claim per batch}
        {--concurrency=0 : Max concurrent deliveries inside a batch}
        {--sleep-ms=0 : Sleep time between empty polls in milliseconds}';

    protected string $description = 'Dispatch ready outbound postbacks using coroutine batches';

    public function handle(PostbackDispatcherService $dispatcher): int
    {
        $batchSize = $this->resolveIntOption('batch', (int) config('postbacks.dispatcher.batch_size', 200));
        $concurrency = $this->resolveIntOption('concurrency', (int) config('postbacks.dispatcher.concurrency', 200));
        $sleepMs = $this->resolveIntOption('sleep-ms', (int) config('postbacks.dispatcher.sleep_ms', 100));
        $runOnce = (bool) $this->option('once');

        do {
            $result = $dispatcher->dispatchReadyBatch($batchSize, $concurrency);

            if ($runOnce) {
                $this->line(sprintf(
                    'claimed=%d sent=%d failed=%d',
                    $result->claimedCount,
                    $result->sentCount,
                    $result->failedCount,
                ));

                return self::SUCCESS;
            }

            if ($result->claimedCount === 0) {
                usleep($sleepMs * 1000);
                continue;
            }

            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    'claimed=%d sent=%d failed=%d',
                    $result->claimedCount,
                    $result->sentCount,
                    $result->failedCount,
                ));
            }
        } while (true);
    }

    private function resolveIntOption(string $name, int $default): int
    {
        $value = (int) $this->option($name);

        if ($value <= 0) {
            return max(1, $default);
        }

        return $value;
    }
}
