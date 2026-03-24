<?php

declare(strict_types=1);

namespace App\PaymentsCore\Domain\Support;

use Hypervel\Support\Facades\Cache;

final class PostbackCircuitBreaker
{
    private const FAILURE_THRESHOLD = 3;
    private const OPEN_DURATION_SECONDS = 300;
    private const FAILURE_WINDOW_SECONDS = 600;

    public static function isAvailable(int $enterpriseId): bool
    {
        return ! Cache::has(self::openKey($enterpriseId));
    }

    public static function recordSuccess(int $enterpriseId): void
    {
        Cache::forget(self::failureCountKey($enterpriseId));
        Cache::forget(self::openKey($enterpriseId));
    }

    public static function recordFailure(int $enterpriseId): void
    {
        $key = self::failureCountKey($enterpriseId);
        $count = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $count, self::FAILURE_WINDOW_SECONDS);

        if ($count >= self::FAILURE_THRESHOLD) {
            Cache::put(self::openKey($enterpriseId), true, self::OPEN_DURATION_SECONDS);
            Cache::forget($key);
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
