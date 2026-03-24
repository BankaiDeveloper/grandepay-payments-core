<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Jobs;

use App\PaymentsCore\Infrastructure\Services\PostbackDeliveryService;
use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\Queueable;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;

class SendSinglePostbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $postbackLogId,
    ) {
        $this->onQueue(config('payment-queues.queues.postback', 'payments-postbacks-high'));
    }

    public function handle(PostbackDeliveryService $deliveryService): void
    {
        $postback = $deliveryService->claimSingleForProcessing($this->postbackLogId);

        if ($postback === null) {
            return;
        }

        $deliveryService->deliver($postback);
    }
}
