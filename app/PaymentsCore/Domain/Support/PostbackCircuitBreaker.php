<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Support;

use Hypervel\Support\Facades\Redis;

final class PostbackCircuitBreaker
{
    private const FAILURE_THRESHOLD = 3;
    private const OPEN_DURATION_SECONDS = 300;
    private const FAILURE_WINDOW_SECONDS = 600;

    public static function isAvailable(int $enterpriseId): bool
    {
        return ! (bool) Redis::get(self::openKey($enterpriseId));
    }

    public static function recordSuccess(int $enterpriseId): void
    {
        Redis::del(self::failureCountKey($enterpriseId));
        Redis::del(self::openKey($enterpriseId));
    }

    public static function recordFailure(int $enterpriseId): void
    {
        $key = self::failureCountKey($enterpriseId);
        $count = (int) Redis::incr($key);

        if ($count === 1) {
            Redis::expire($key, self::FAILURE_WINDOW_SECONDS);
        }

        if ($count >= self::FAILURE_THRESHOLD) {
            Redis::setex(self::openKey($enterpriseId), self::OPEN_DURATION_SECONDS, '1');
            Redis::del($key);
        }
    }

    private static function failureCountKey(int $enterpriseId): string
    {
        return "postback_cb:failures:{$enterpriseId}";
    }

    private static function openKey(int $enterpriseId): string
    {
        return "postback_cb:open:{$enterpriseId}";
    }
}
