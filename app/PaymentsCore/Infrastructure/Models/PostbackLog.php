<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Models;

use App\Models\Model;
use Hyperf\Database\Model\Builder;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class PostbackLog extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD_LETTER = 'dead_letter';

    protected ?string $table = 'postback_logs';

    protected array $fillable = [
        'uuid', 'enterprise_id', 'transaction_id', 'withdrawal_id',
        'event', 'url', 'payload', 'signed_payload', 'signature',
        'status', 'http_status_code', 'response_body', 'attempts',
        'next_retry_at', 'locked_at', 'processing_job_uuid',
        'last_attempted_at', 'completed_at', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'http_status_code' => 'integer',
            'next_retry_at' => 'datetime',
            'locked_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class);
    }

    public function markAsSent(int $httpStatusCode, ?string $responseBody = null): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'http_status_code' => $httpStatusCode,
            'response_body' => $responseBody ? mb_substr($responseBody, 0, 2000) : null,
            'last_attempted_at' => now(),
            'completed_at' => now(),
            'attempts' => $this->attempts + 1,
            'next_retry_at' => null,
            'locked_at' => null,
            'processing_job_uuid' => null,
        ]);
    }

    public function markAsFailed(int $attempt, ?string $errorMessage = null, ?int $httpStatusCode = null, ?string $responseBody = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'attempts' => $attempt,
            'http_status_code' => $httpStatusCode,
            'response_body' => $responseBody ? mb_substr($responseBody, 0, 2000) : null,
            'last_attempted_at' => now(),
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 2000) : null,
            'locked_at' => null,
            'processing_job_uuid' => null,
        ]);
    }

    public function markAsDeadLetter(?string $errorMessage = null): void
    {
        $this->update([
            'status' => self::STATUS_DEAD_LETTER,
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 2000) : null,
            'last_attempted_at' => now(),
            'completed_at' => now(),
            'next_retry_at' => null,
            'locked_at' => null,
            'processing_job_uuid' => null,
        ]);
    }

    public function scopeReadyToSend(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->where(function (Builder $q): void {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }
}
