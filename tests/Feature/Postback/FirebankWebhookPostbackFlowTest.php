<?php

declare(strict_types=1);

namespace Tests\Feature\Postback;

use App\PaymentsCore\Infrastructure\Jobs\SendSinglePostbackJob;
use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\PaymentProvider;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\Wallet;
use App\PaymentsCore\Infrastructure\Models\WebhookLog;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Queue;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class FirebankWebhookPostbackFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('postbacks.benchmark_allowed_hosts', ['127.0.0.1', 'localhost']);
    }

    public function testFirebankPaidWebhookCreatesPostbackAndDispatchesJob(): void
    {
        Queue::fake([SendSinglePostbackJob::class]);

        [$transaction, $enterprise] = $this->createPendingFirebankTransaction();

        $response = $this->postJson('/api/webhooks/firebank', [
            'event' => 'CashIn',
            'status' => 'CONFIRMED',
            'externalId' => $transaction->external_id,
            'transactionId' => 'fb-tx-100',
            'amount' => 15.00,
            'feeAmount' => 0,
            'finalAmount' => 15.00,
            'endToEndId' => 'E2E-100',
            'counterpart' => [
                'name' => 'Cliente Hypervel',
                'document' => '12345678901',
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['acknowledged' => true]);

        $transaction->refresh();

        $this->assertSame(Transaction::STATUS_PAID, $transaction->status);
        $this->assertSame('fb-tx-100', $transaction->provider_transaction_id);
        $this->assertSame('E2E-100', $transaction->end_to_end_id);

        $wallet = Wallet::query()->where('enterprise_id', $enterprise->id)->firstOrFail();
        $this->assertSame(1500, $wallet->balance_cents);

        $webhookLog = WebhookLog::query()->where('transaction_id', $transaction->id)->firstOrFail();
        $this->assertTrue($webhookLog->processed);

        $postbackLog = PostbackLog::query()->where('transaction_id', $transaction->id)->firstOrFail();

        $this->assertSame('transaction.paid', $postbackLog->event);
        $this->assertSame('http://127.0.0.1:18080/postbacks', $postbackLog->url);
        $this->assertSame(PostbackLog::STATUS_PENDING, $postbackLog->status);
        $this->assertSame(
            hash_hmac('sha256', (string) $postbackLog->signed_payload, 'enterprise-secret'),
            $postbackLog->signature,
        );

        Queue::assertPushed(SendSinglePostbackJob::class, function (SendSinglePostbackJob $job) use ($postbackLog): bool {
            return $job->postbackLogId === $postbackLog->id;
        });
    }

    /**
     * @return array{Transaction, Enterprise}
     */
    private function createPendingFirebankTransaction(): array
    {
        $enterprise = Enterprise::query()->create([
            'name' => 'GrandePay Hypervel',
            'document' => '12345678000199',
            'email' => 'hypervel-enterprise@grandepay.test',
            'is_active' => true,
            'settings' => [
                'webhook_url' => 'http://127.0.0.1:18080/postbacks',
                'secret_key' => 'enterprise-secret',
            ],
        ]);

        PaymentProvider::query()->create([
            'name' => 'Firebank',
            'code' => 'firebank',
            'is_active' => true,
            'webhook_secret' => 'benchmark-secret-key',
        ]);

        $provider = PaymentProvider::query()->where('code', 'firebank')->firstOrFail();

        Wallet::query()->create([
            'enterprise_id' => $enterprise->id,
            'balance_cents' => 0,
            'blocked_cents' => 0,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $transaction = Transaction::query()->create([
            'enterprise_id' => $enterprise->id,
            'payment_provider_id' => $provider->id,
            'type' => 'cash_in',
            'status' => Transaction::STATUS_PENDING,
            'external_id' => 'GP-HYP-POSTBACK-001',
            'amount_cents' => 1500,
            'fee_cents' => 0,
            'net_amount_cents' => 1500,
            'currency' => 'BRL',
            'payment_method' => 'pix',
        ]);

        return [$transaction, $enterprise];
    }
}
