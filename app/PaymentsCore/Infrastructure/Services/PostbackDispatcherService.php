<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\ValueObjects\PostbackDeliveryResult;
use App\PaymentsCore\Domain\ValueObjects\PostbackDispatchResult;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;

final class PostbackDispatcherService
{
    public function __construct(
        private readonly PostbackDeliveryService $deliveryService,
    ) {}

    public function dispatchReadyBatch(int $batchSize, int $concurrency): PostbackDispatchResult
    {
        $postbacks = $this->claimReadyBatch($batchSize);

        if ($postbacks === []) {
            return new PostbackDispatchResult(
                claimedCount: 0,
                sentCount: 0,
                failedCount: 0,
            );
        }

        $results = $this->deliverClaimedPostbacks(
            $postbacks,
            max(1, min($concurrency, count($postbacks))),
        );

        $sentCount = count(array_filter(
            $results,
            static fn (PostbackDeliveryResult $result): bool => $result->sent,
        ));

        return new PostbackDispatchResult(
            claimedCount: count($postbacks),
            sentCount: $sentCount,
            failedCount: count($postbacks) - $sentCount,
        );
    }

    /**
     * @return list<PostbackLog>
     */
    private function claimReadyBatch(int $limit): array
    {
        $limit = max(1, $limit);

        return DB::transaction(function () use ($limit): array {
            $now = now();
            $leaseCutoff = $now->copy()->subMinutes($this->leaseTimeoutMinutes());
            $driver = DB::connection()->getDriverName();

            if ($driver !== 'pgsql') {
                $ids = PostbackLog::query()
                    ->whereIn('status', [PostbackLog::STATUS_PENDING, PostbackLog::STATUS_FAILED])
                    ->where(function ($query) use ($now): void {
                        $query->whereNull('next_retry_at')
                            ->orWhere('next_retry_at', '<=', $now);
                    })
                    ->where(function ($query) use ($leaseCutoff): void {
                        $query->whereNull('locked_at')
                            ->orWhere('locked_at', '<', $leaseCutoff);
                    })
                    ->orderBy('id')
                    ->limit($limit)
                    ->get(['id'])
                    ->pluck('id')
                    ->map(static fn (int|string $id): int => (int) $id)
                    ->all();

                if ($ids === []) {
                    return [];
                }

                PostbackLog::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'locked_at' => $now,
                        'processing_job_uuid' => (string) Str::uuid(),
                    ]);

                return PostbackLog::query()
                    ->with('enterprise:id,settings')
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->get()
                    ->all();
            }

            $rows = DB::select(
                "SELECT id
                FROM postback_logs
                WHERE status IN (?, ?)
                  AND (next_retry_at IS NULL OR next_retry_at <= ?)
                  AND (locked_at IS NULL OR locked_at < ?)
                ORDER BY id
                LIMIT {$limit}
                FOR UPDATE SKIP LOCKED",
                [
                    PostbackLog::STATUS_PENDING,
                    PostbackLog::STATUS_FAILED,
                    $now,
                    $leaseCutoff,
                ],
            );

            $ids = array_map(
                static fn (object $row): int => (int) $row->id,
                $rows,
            );

            if ($ids === []) {
                return [];
            }

            PostbackLog::query()
                ->whereIn('id', $ids)
                ->update([
                    'locked_at' => $now,
                    'processing_job_uuid' => (string) Str::uuid(),
                ]);

            return PostbackLog::query()
                ->with('enterprise:id,settings')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get()
                ->all();
        });
    }

    /**
     * @param  list<PostbackLog>  $postbacks
     * @return list<PostbackDeliveryResult>
     */
    private function deliverClaimedPostbacks(array $postbacks, int $concurrency): array
    {
        if (! $this->supportsCoroutines() || $concurrency <= 1 || count($postbacks) <= 1) {
            return array_map(
                fn (PostbackLog $postback): PostbackDeliveryResult => $this->deliveryService->deliver($postback),
                $postbacks,
            );
        }

        $results = [];

        $runner = function () use ($postbacks, $concurrency, &$results): void {
            $results = [];
            $input = new \Swoole\Coroutine\Channel(count($postbacks));
            $output = new \Swoole\Coroutine\Channel(count($postbacks));

            foreach ($postbacks as $postback) {
                $input->push($postback);
            }

            $input->close();

            $workerCount = min($concurrency, count($postbacks));

            for ($index = 0; $index < $workerCount; $index++) {
                go(function () use ($input, $output): void {
                    while (true) {
                        $postback = $input->pop();

                        if ($postback === false) {
                            if ($input->errCode === SWOOLE_CHANNEL_CLOSED) {
                                break;
                            }

                            continue;
                        }

                        $output->push($this->deliveryService->deliver($postback));
                    }
                });
            }

            for ($index = 0; $index < count($postbacks); $index++) {
                $result = $output->pop();

                if ($result instanceof PostbackDeliveryResult) {
                    $results[] = $result;
                }
            }

            $output->close();
        };

        if ($this->isInsideCoroutine()) {
            $runner();

            return $results;
        }

        \Swoole\Coroutine\run($runner);

        return $results;
    }

    private function supportsCoroutines(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && class_exists(\Swoole\Coroutine\Channel::class)
            && function_exists('go');
    }

    private function isInsideCoroutine(): bool
    {
        if (! class_exists(\Swoole\Coroutine::class)) {
            return false;
        }

        return \Swoole\Coroutine::getCid() > 0;
    }

    private function leaseTimeoutMinutes(): int
    {
        return max(1, (int) config('postbacks.dispatcher.lease_timeout_minutes', 5));
    }
}
