<?php

declare(strict_types=1);

namespace Tests\Feature\Postback;

use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Http;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PostbackDispatchCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('postbacks.benchmark_allowed_hosts', ['127.0.0.1', 'localhost']);
    }

    public function testCommandDispatchesReadyPostbacksFromOutbox(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $enterprise = Enterprise::query()->create([
            'name' => 'GrandePay Receiver',
            'document' => '12345678000199',
            'email' => 'receiver@grandepay.test',
            'is_active' => true,
            'settings' => [
                'webhook_url' => 'http://127.0.0.1:18080/postbacks',
                'secret_key' => 'enterprise-secret',
            ],
        ]);

        $payload = [
            'event' => 'transaction.paid',
            'data' => ['id' => 'tx_123'],
        ];

        $signedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $postback = PostbackLog::query()->create([
            'enterprise_id' => $enterprise->id,
            'event' => 'transaction.paid',
            'url' => 'http://127.0.0.1:18080/postbacks',
            'payload' => $payload,
            'signed_payload' => $signedPayload,
            'signature' => hash_hmac('sha256', $signedPayload, 'enterprise-secret'),
            'status' => PostbackLog::STATUS_PENDING,
            'attempts' => 0,
        ]);

        $this->artisan('postbacks:dispatch', [
            '--once' => true,
            '--batch' => 50,
            '--concurrency' => 10,
        ])->assertExitCode(0);

        $postback->refresh();

        $this->assertSame(PostbackLog::STATUS_SENT, $postback->status);
        $this->assertSame(1, $postback->attempts);
        $this->assertSame(200, $postback->http_status_code);
        $this->assertNull($postback->locked_at);
        $this->assertNull($postback->processing_job_uuid);
    }
}
