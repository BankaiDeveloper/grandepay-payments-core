<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected ?string $table = 'wallets';

    protected array $fillable = [
        'enterprise_id',
        'balance_cents',
        'blocked_cents',
        'currency',
        'is_active',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
            'blocked_cents' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function availableBalanceCents(): int
    {
        return $this->balance_cents - $this->blocked_cents;
    }
}
