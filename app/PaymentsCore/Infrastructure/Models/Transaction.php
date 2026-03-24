<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use App\PaymentsCore\Domain\Enums\TransactionStatus;
use Hyperf\Database\Model\Builder;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_PAID = 2;
    public const STATUS_FAILED = 3;
    public const STATUS_REFUNDED = 4;
    public const STATUS_CANCELLED = 5;
    public const STATUS_CHARGEBACK = 6;

    protected ?string $table = 'transactions';

    protected array $fillable = [
        'enterprise_id',
        'payment_provider_id',
        'provider_route_id',
        'routing_operation',
        'type',
        'status',
        'idempotency_key',
        'request_id',
        'external_id',
        'provider_transaction_id',
        'end_to_end_id',
        'correlation_id',
        'amount_cents',
        'fee_cents',
        'net_amount_cents',
        'refunded_amount_cents',
        'currency',
        'payment_method',
        'pix_key',
        'pix_key_type',
        'pix_code',
        'pix_expiration',
        'description',
        'payer_name',
        'payer_document',
        'payer_email',
        'receiver_name',
        'receiver_document',
        'provider_raw_response',
        'provider_raw_webhook',
        'metadata',
        'error_code',
        'error_message',
        'paid_at',
        'failed_at',
        'refunded_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'net_amount_cents' => 'integer',
            'refunded_amount_cents' => 'integer',
            'provider_raw_response' => 'array',
            'provider_raw_webhook' => 'array',
            'metadata' => 'array',
            'pix_expiration' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function creating(\Hyperf\Database\Model\Events\Creating $event): void
    {
        if (empty($this->uuid)) {
            $this->uuid = (string) \Hypervel\Support\Str::uuid();
        }
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    public function scopeForEnterprise(Builder $query, int $enterpriseId): Builder
    {
        return $query->where('enterprise_id', $enterpriseId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isChargeback(): bool
    {
        return $this->status === self::STATUS_CHARGEBACK;
    }
}
