<?php

declare(strict_types=1);

return [
    'queues' => [
        'high' => env('PAYMENTS_QUEUE_HIGH', 'payments-webhooks-high'),
        'medium' => env('PAYMENTS_QUEUE_MEDIUM', 'payments-withdrawals-medium'),
        'default' => env('PAYMENTS_QUEUE_DEFAULT', 'payments-default'),
        'low' => env('PAYMENTS_QUEUE_LOW', 'payments-low'),
        'postback' => env('PAYMENTS_QUEUE_POSTBACK', 'payments-postbacks-high'),
    ],

    'postback_connection' => env('PAYMENTS_POSTBACK_CONNECTION', 'redis'),

    'retry' => [
        'max_attempts' => 10,
        'backoff' => [10, 30, 60, 120, 300, 600],
    ],
];
