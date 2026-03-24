<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use App\PaymentsCore\Domain\Support\WithdrawalMode;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;

class Withdrawal extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_FAILED = 3;
    public const STATUS_CANCELLED = 4;

    protected ?string $table = 'withdrawals';

    protected array $fillable = [
        'enterprise_id',
        'payment_provider_id',
        'provider_route_id',
        'transaction_id',
        'status',
        'idempotency_key',
        'external_id',
        'provider_withdrawal_id',
        'end_to_end_id',
        'amount_cents',
        'fee_cents',
        'net_amount_cents',
        'currency',
        'pix_key',
        'pix_key_type',
        'recipient_name',
        'recipient_document',
        'description',
        'provider_raw_response',
        'provider_raw_webhook',
        'metadata',
        'error_code',
        'error_message',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'net_amount_cents' => 'integer',
            'provider_raw_response' => 'array',
            'provider_raw_webhook' => 'array',
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function getProcessingModeAttribute(): string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return WithdrawalMode::isValidMode($metadata['withdrawal_mode'] ?? null)
            ? $metadata['withdrawal_mode']
            : WithdrawalMode::AUTOMATIC;
    }

    public function getFundsStateAttribute(): string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $fundsState = $metadata['funds_state'] ?? null;

        return is_string($fundsState) && $fundsState !== ''
            ? $fundsState
            : WithdrawalMode::FUNDS_DEBITED;
    }

    public function getRequiresManualApprovalAttribute(): bool
    {
        return $this->processing_mode === WithdrawalMode::MANUAL_APPROVAL;
    }
}
