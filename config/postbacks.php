<?php

declare(strict_types=1);

return [
    'benchmark_allowed_hosts' => array_filter(
        explode(',', env('POSTBACK_BENCHMARK_ALLOWED_HOSTS', '')),
        static fn (string $host): bool => $host !== '',
    ),
];
