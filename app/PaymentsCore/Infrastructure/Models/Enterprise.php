<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;

class Enterprise extends Model
{
    protected ?string $table = 'enterprises';

    protected array $fillable = [
        'name',
        'document',
        'email',
        'is_active',
        'settings',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function getWebhookUrlAttribute(): ?string
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $webhookUrl = $settings['webhook_url'] ?? null;

        return is_string($webhookUrl) && $webhookUrl !== '' ? $webhookUrl : null;
    }

    public function getSecretKeyAttribute(): ?string
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $secretKey = $settings['secret_key'] ?? null;

        return is_string($secretKey) && $secretKey !== '' ? $secretKey : null;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }
}
