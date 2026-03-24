<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;

class GateSetting extends Model
{
    protected ?string $table = 'gate_settings';

    protected array $fillable = [
        'scope',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
