<?php

declare(strict_types=1);

namespace App\PaymentsCore\Infrastructure\Services;

use App\PaymentsCore\Domain\Contracts\PostbackServiceInterface;
use App\PaymentsCore\Domain\Support\PostbackNetworkGuard;
use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\Withdrawal;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;
use JsonException;

final class PostbackService implements PostbackServiceInterface
{
    public function notifyTransaction(string $event, Transaction $transaction): void
    {
        $enterprise = $transaction->relationLoaded('enterprise')
            ? $transaction->enterprise
            : $transaction->enterprise()->first();

        if (! $enterprise instanceof Enterprise) {
            return;
        }

        $url = $this->resolveTransactionUrl($transaction, $enterprise);

        if ($url === null || $url === '') {
            return;
        }

        $payload = $this->buildTransactionPayload($event, $transaction);
        $this->createAndDispatch($event, $payload, $url, $enterprise, $transaction->id, null);
    }

    public function notifyWithdrawal(string $event, Withdrawal $withdrawal): void
    {
        $enterprise = $withdrawal->relationLoaded('enterprise')
            ? $withdrawal->enterprise
            : $withdrawal->enterprise()->first();

        if (! $enterprise instanceof Enterprise) {
            return;
        }

        $settings = is_array($enterprise->settings) ? $enterprise->settings : [];
        $url = $settings['webhook_url'] ?? $enterprise->webhook_url ?? null;

        if ($url === null || $url === '') {
            return;
        }

        $payload = $this->buildWithdrawalPayload($event, $withdrawal);
        $this->createAndDispatch($event, $payload, $url, $enterprise, null, $withdrawal->id);
    }

    private function signPayload(string $jsonPayload, string $secretKey): string
    {
        return hash_hmac('sha256', $jsonPayload, $secretKey);
    }

    private function resolveTransactionUrl(Transaction $transaction, Enterprise $enterprise): ?string
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $postbackUrl = $metadata['postback_url'] ?? null;

        if (is_string($postbackUrl) && $postbackUrl !== '') {
            return $postbackUrl;
        }

        $settings = is_array($enterprise->settings) ? $enterprise->settings : [];

        return $settings['webhook_url'] ?? $enterprise->webhook_url ?? null;
    }

    private function buildTransactionPayload(string $event, Transaction $transaction): array
    {
        return [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'id' => $transaction->uuid,
                'external_id' => $transaction->external_id,
                'type' => $transaction->type,
                'status' => $this->mapTransactionStatus($transaction->status),
                'amount' => $transaction->amount_cents,
                'fee' => $transaction->fee_cents,
                'net_amount' => $transaction->net_amount_cents,
                'refunded_amount' => $transaction->refunded_amount_cents ?? 0,
                'currency' => $transaction->currency,
                'payment_method' => $transaction->payment_method,
                'pix_code' => $transaction->pix_code,
                'pix_expiration' => $transaction->pix_expiration?->toIso8601String(),
                'end_to_end_id' => $transaction->end_to_end_id,
                'description' => $transaction->description,
                'payer' => [
                    'name' => $transaction->payer_name,
                    'document' => $transaction->payer_document,
                    'email' => $transaction->payer_email,
                ],
                'error_code' => $transaction->error_code,
                'error_message' => $transaction->error_message,
                'paid_at' => $transaction->paid_at?->toIso8601String(),
                'failed_at' => $transaction->failed_at?->toIso8601String(),
                'refunded_at' => $transaction->refunded_at?->toIso8601String(),
                'created_at' => $transaction->created_at?->toIso8601String(),
            ],
        ];
    }

    private function buildWithdrawalPayload(string $event, Withdrawal $withdrawal): array
    {
        return [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'id' => $withdrawal->uuid,
                'external_id' => $withdrawal->external_id,
                'status' => $this->mapWithdrawalStatus($withdrawal->status),
                'amount' => $withdrawal->amount_cents,
                'fee' => $withdrawal->fee_cents,
                'net_amount' => $withdrawal->net_amount_cents,
                'currency' => $withdrawal->currency,
                'pix_key' => $withdrawal->pix_key,
                'pix_key_type' => $withdrawal->pix_key_type,
                'recipient' => [
                    'name' => $withdrawal->recipient_name,
                    'document' => $withdrawal->recipient_document,
                ],
                'end_to_end_id' => $withdrawal->end_to_end_id,
                'description' => $withdrawal->description,
                'error_code' => $withdrawal->error_code,
                'error_message' => $withdrawal->error_message,
                'completed_at' => $withdrawal->completed_at?->toIso8601String(),
                'failed_at' => $withdrawal->failed_at?->toIso8601String(),
                'created_at' => $withdrawal->created_at?->toIso8601String(),
            ],
        ];
    }

    private function createAndDispatch(
        string $event,
        array $payload,
        string $url,
        Enterprise $enterprise,
        ?int $transactionId,
        ?int $withdrawalId,
    ): void {
        if (! PostbackNetworkGuard::isAllowedUrl($url)) {
            Log::warning('Postback URL blocked by security policy', [
                'event' => $event,
                'enterprise_id' => $enterprise->id,
                'url' => $url,
            ]);
            return;
        }

        try {
            $jsonPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            Log::error('Postback payload encoding failed', [
                'event' => $event,
                'enterprise_id' => $enterprise->id,
                'error' => $exception->getMessage(),
            ]);

            PostbackLog::query()->firstOrCreate(
                ['event' => $event, 'transaction_id' => $transactionId, 'withdrawal_id' => $withdrawalId],
                [
                    'enterprise_id' => $enterprise->id,
                    'url' => $url,
                    'payload' => ['_encoding_error' => $exception->getMessage()],
                    'status' => PostbackLog::STATUS_DEAD_LETTER,
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                    'attempts' => 0,
                ],
            );
            return;
        }

        $settings = is_array($enterprise->settings) ? $enterprise->settings : [];
        $secretKey = $settings['secret_key'] ?? $enterprise->secret_key ?? '';
        $signature = $this->signPayload($jsonPayload, $secretKey);

        try {
            $postbackLog = PostbackLog::query()->create([
                'enterprise_id' => $enterprise->id,
                'transaction_id' => $transactionId,
                'withdrawal_id' => $withdrawalId,
                'event' => $event,
                'url' => $url,
                'payload' => $payload,
                'signed_payload' => $jsonPayload,
                'signature' => $signature,
                'status' => PostbackLog::STATUS_PENDING,
            ]);
        } catch (\Hypervel\Database\QueryException $e) {
            if ($this->isDuplicateOutboxViolation($e)) {
                $postbackLog = $this->findExistingOutboxLog($event, $transactionId, $withdrawalId);

                if ($postbackLog instanceof PostbackLog) {
                    return;
                }
            }

            throw $e;
        }

    }

    private function findExistingOutboxLog(string $event, ?int $transactionId, ?int $withdrawalId): ?PostbackLog
    {
        return DB::transaction(function () use ($event, $transactionId, $withdrawalId): ?PostbackLog {
            $query = PostbackLog::query()
                ->where('event', $event);

            if ($transactionId !== null) {
                $query->where('transaction_id', $transactionId)
                    ->whereNull('withdrawal_id');
            } else {
                $query->where('withdrawal_id', $withdrawalId)
                    ->whereNull('transaction_id');
            }

            return $query
                ->lockForUpdate()
                ->first();
        });
    }

    private function isDuplicateOutboxViolation(\Hypervel\Database\QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $sqlState = (string) $exception->getCode();

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($message, 'postback_logs_event_transaction_unique')
            || str_contains($message, 'postback_logs_event_withdrawal_unique');
    }

    private function mapTransactionStatus(int $status): string
    {
        return match ($status) {
            Transaction::STATUS_PENDING => 'pending',
            Transaction::STATUS_PAID => 'paid',
            Transaction::STATUS_FAILED => 'failed',
            Transaction::STATUS_REFUNDED => 'refunded',
            Transaction::STATUS_CANCELLED => 'cancelled',
            Transaction::STATUS_CHARGEBACK => 'chargeback',
            default => 'unknown',
        };
    }

    private function mapWithdrawalStatus(int $status): string
    {
        return match ($status) {
            Withdrawal::STATUS_PENDING => 'pending',
            Withdrawal::STATUS_PROCESSING => 'processing',
            Withdrawal::STATUS_COMPLETED => 'completed',
            Withdrawal::STATUS_FAILED => 'failed',
            Withdrawal::STATUS_CANCELLED => 'cancelled',
            default => 'unknown',
        };
    }
}
