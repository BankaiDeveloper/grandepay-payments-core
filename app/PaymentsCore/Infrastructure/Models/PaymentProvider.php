<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

class PaymentProvider extends Model
{
    protected ?string $table = 'payment_providers';

    protected array $fillable = [
        'name',
        'code',
        'is_active',
        'base_url',
        'client_key',
        'client_secret',
        'api_key',
        'webhook_secret',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }
}
