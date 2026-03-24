<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\AbstractController;

final class SystemController extends AbstractController
{
    public function show(): array
    {
        return [
            'service' => 'grandepay-payments-core',
            'api_version' => env('PAYMENTS_API_VERSION', 'v1'),
            'framework' => 'hypervel',
            'status' => 'bootstrapped',
            'modules' => [
                'cash_in' => 'active',
                'cash_out' => 'active',
                'webhooks' => 'planned',
                'postbacks' => 'active',
                'chargebacks' => 'active',
            ],
        ];
    }
}
