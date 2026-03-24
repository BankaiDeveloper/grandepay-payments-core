<?php

declare(strict_types=1);

return [
    App\PaymentsCore\Infrastructure\Process\WebhookIngestProcess::class,
    App\PaymentsCore\Infrastructure\Process\FinancialProcessorProcess::class,
    App\PaymentsCore\Infrastructure\Process\PostbackDispatcherProcess::class,
];
