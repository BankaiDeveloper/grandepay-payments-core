<?php

declare(strict_types=1);

namespace Tests\Feature\Postback;

use App\PaymentsCore\Infrastructure\Jobs\SendSinglePostbackJob;
use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\PostbackLog;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Http;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SendSinglePostbackJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('postbacks.benchmark_allowed_hosts', ['127.0.0.1', 'localhost']);
    }

    public function testJobSendsSignedPostbackAndMarksLogAsSent(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $enterprise = $this->createEnterprise();
        $postback = $this->createPostback($enterprise->id);

        (new SendSinglePostbackJob($postback->id))->handle();

        $postback->refresh();

        $this->assertSame(PostbackLog::STATUS_SENT, $postback->status);
        $this->assertSame(1, $postback->attempts);
        $this->assertSame(200, $postback->http_status_code);
        $this->assertNull($postback->locked_at);
        $this->assertNull($postback->processing_job_uuid);

        Http::assertSent(function ($request) use ($postback): bool {
            return $request->url() === 'http://127.0.0.1:18080/postbacks'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Event', ['transaction.paid'])
                && $request->hasHeader('X-Postback-Id', [$postback->uuid])
                && $request->hasHeader(
                    'X-Signature-256',
                    [hash_hmac('sha256', (string) $postback->signed_payload, 'enterprise-secret')]
                )
                && $request->body() === (string) $postback->signed_payload;
        });
    }

    public function testJobMarksPostbackAsFailedAndSchedulesRetryWhenHttpFails(): void
    {
        Http::fake([
            '*' => Http::response(['error' => true], 500),
        ]);

        $enterprise = $this->createEnterprise();
        $postback = $this->createPostback($enterprise->id);

        (new SendSinglePostbackJob($postback->id))->handle();

        $postback->refresh();

        $this->assertSame(PostbackLog::STATUS_FAILED, $postback->status);
        $this->assertSame(1, $postback->attempts);
        $this->assertSame(500, $postback->http_status_code);
        $this->assertNotNull($postback->next_retry_at);
        $this->assertNull($postback->locked_at);
        $this->assertNull($postback->processing_job_uuid);
    }

    private function createEnterprise(): Enterprise
    {
        return Enterprise::query()->create([
            'name' => 'GrandePay Receiver',
            'document' => '98765432000199',
            'email' => 'receiver@grandepay.test',
            'is_active' => true,
            'settings' => [
                'webhook_url' => 'http://127.0.0.1:18080/postbacks',
                'secret_key' => 'enterprise-secret',
            ],
        ]);
    }

    private function createPostback(int $enterpriseId): PostbackLog
    {
        $payload = [
            'event' => 'transaction.paid',
            'data' => ['id' => 'tx_123'],
        ];

        $signedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return PostbackLog::query()->create([
            'enterprise_id' => $enterpriseId,
            'event' => 'transaction.paid',
            'url' => 'http://127.0.0.1:18080/postbacks',
            'payload' => $payload,
            'signed_payload' => $signedPayload,
            'signature' => hash_hmac('sha256', $signedPayload, 'enterprise-secret'),
            'status' => PostbackLog::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }
}
