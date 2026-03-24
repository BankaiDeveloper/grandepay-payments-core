<?php

declare(strict_types=1);

return [
    'benchmark_allowed_hosts' => array_filter(
        explode(',', env('POSTBACK_BENCHMARK_ALLOWED_HOSTS', '')),
        static fn (string $host): bool => $host !== '',
    ),
    'dns_cache_ttl' => (int) env('POSTBACK_DNS_CACHE_TTL', 60),
    'dispatcher' => [
        'batch_size' => (int) env('POSTBACK_DISPATCHER_BATCH_SIZE', 200),
        'concurrency' => (int) env('POSTBACK_DISPATCHER_CONCURRENCY', 200),
        'sleep_ms' => (int) env('POSTBACK_DISPATCHER_SLEEP_MS', 100),
        'lease_timeout_minutes' => (int) env('POSTBACK_DISPATCHER_LEASE_TIMEOUT_MINUTES', 5),
    ],
];
