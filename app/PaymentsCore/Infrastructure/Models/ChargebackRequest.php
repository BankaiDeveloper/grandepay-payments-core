<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use Hyperf\Database\Model\Builder;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class ChargebackRequest extends Model
{
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_ENTERPRISE = 'enterprise';
    public const SOURCE_ADMIN = 'admin';

    public const EXECUTION_PROVIDER_API = 'provider_api';
    public const EXECUTION_INTERNAL_ADJUSTMENT = 'internal_adjustment';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD_LETTER = 'dead_letter';
    public const STATUS_REJECTED = 'rejected';

    protected ?string $table = 'chargeback_requests';

    protected array $fillable = [
        'original_transaction_id',
        'enterprise_id',
        'payment_provider_id',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'webhook_log_id',
        'source',
        'execution_mode',
        'status',
        'reason_code',
        'idempotency_key',
        'request_id',
        'amount_cents',
        'reason',
        'provider_reference',
        'provider_end_to_end_id',
        'provider_status',
        'provider_fee_cents',
        'provider_response_payload',
        'error_code',
        'error_message',
        'attempts_count',
        'queued_at',
        'last_attempt_at',
        'locked_at',
        'processing_job_uuid',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'processed_at',
        'failed_at',
        'replay_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'provider_fee_cents' => 'integer',
            'attempts_count' => 'integer',
            'provider_response_payload' => 'array',
            'metadata' => 'array',
            'queued_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'locked_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'replay_count' => 'integer',
        ];
    }

    public function creating(\Hyperf\Database\Model\Events\Creating $event): void
    {
        if (empty($this->uuid)) {
            $this->uuid = (string) \Hypervel\Support\Str::uuid();
        }
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'original_transaction_id');
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function webhookLog(): BelongsTo
    {
        return $this->belongsTo(WebhookLog::class);
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeByEnterprise(Builder $query, int $enterpriseId): Builder
    {
        return $query->where('enterprise_id', $enterpriseId);
    }
}
