<?php

declare(strict_types=1);

namespace Tests\Feature\Postback;

use App\PaymentsCore\Infrastructure\Models\PaymentProvider;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\File;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BenchmarkSeedPostbackCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testCommandSeedsTransactionsAndWritesManifest(): void
    {
        $manifestRelativePath = 'storage/app/benchmarks/testing-postback-manifest.json';
        $manifestAbsolutePath = base_path($manifestRelativePath);

        File::delete($manifestAbsolutePath);

        $this->artisan('benchmark:seed-postback', [
            '--count' => 6,
            '--enterprises' => 2,
            '--postback-url' => 'http://127.0.0.1:18080/postbacks',
            '--output' => $manifestRelativePath,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('transactions', 6);
        $this->assertDatabaseCount('enterprises', 2);

        $provider = PaymentProvider::query()->where('code', 'firebank')->firstOrFail();

        $this->assertSame('benchmark-secret-key', $provider->webhook_secret);
        $this->assertTrue(File::exists($manifestAbsolutePath));

        $manifest = json_decode((string) File::get($manifestAbsolutePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('/api/webhooks/firebank', $manifest['webhook_endpoint']);
        $this->assertSame('http://127.0.0.1:18080/postbacks', $manifest['benchmark']['postback_url']);
        $this->assertCount(6, $manifest['transactions']);
        $this->assertSame(
            6,
            Transaction::query()->whereIn('external_id', array_column($manifest['transactions'], 'external_id'))->count(),
        );

        File::delete($manifestAbsolutePath);
    }
}
