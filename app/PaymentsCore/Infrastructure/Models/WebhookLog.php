<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasOne;

class WebhookLog extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_DEAD_LETTER = 'dead_letter';

    protected ?string $table = 'webhook_logs';

    protected array $fillable = [
        'uuid',
        'payment_provider_id',
        'enterprise_id',
        'transaction_id',
        'withdrawal_id',
        'event_type',
        'provider_event_id',
        'idempotency_key',
        'ip_address',
        'headers',
        'payload',
        'response_code',
        'response_body',
        'processed',
        'processing_status',
        'attempts_count',
        'last_attempt_at',
        'next_retry_at',
        'signature_valid',
        'signature_error',
        'raw_body',
        'request_path',
        'request_method',
        'processing_error',
        'locked_at',
        'processing_job_uuid',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'response_body' => 'array',
            'processed' => 'boolean',
            'attempts_count' => 'integer',
            'signature_valid' => 'boolean',
            'last_attempt_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'locked_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function walletTransaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class);
    }
}
