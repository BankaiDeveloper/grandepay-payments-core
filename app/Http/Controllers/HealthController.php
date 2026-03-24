<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class HealthController extends AbstractController
{
    public function index(): array
    {
        return [
            'service' => 'grandepay-payments-core',
            'framework' => 'hypervel',
            'status' => 'ok',
        ];
    }

    public function up(): array
    {
        return [
            'status' => 'ok',
            'service' => 'grandepay-payments-core',
        ];
    }
}
