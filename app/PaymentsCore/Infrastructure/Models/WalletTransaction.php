<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected ?string $table = 'wallet_transactions';

    protected array $fillable = [
        'wallet_id',
        'enterprise_id',
        'transaction_id',
        'withdrawal_id',
        'webhook_log_id',
        'type',
        'amount_cents',
        'balance_before_cents',
        'balance_after_cents',
        'description',
        'provider_code',
        'metadata',
        'initiated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'balance_before_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'metadata' => 'array',
            'initiated_by_user_id' => 'integer',
        ];
    }

    public function creating(\Hyperf\Database\Model\Events\Creating $event): void
    {
        if (empty($this->uuid)) {
            $this->uuid = (string) \Hypervel\Support\Str::uuid();
        }
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function webhookLog(): BelongsTo
    {
        return $this->belongsTo(WebhookLog::class);
    }
}
