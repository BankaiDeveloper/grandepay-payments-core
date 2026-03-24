<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\PaymentsCore\Infrastructure\Models\Enterprise;
use App\PaymentsCore\Infrastructure\Models\PaymentProvider;
use App\PaymentsCore\Infrastructure\Models\Transaction;
use App\PaymentsCore\Infrastructure\Models\Wallet;
use Hypervel\Console\Command;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;

class BenchmarkSeedPostbackCommand extends Command
{
    protected ?string $signature = 'benchmark:seed-postback
        {--count=1000 : Number of transactions to seed}
        {--enterprises=10 : Number of enterprises}
        {--postback-url=http://127.0.0.1:18080/postbacks : Enterprise webhook URL}
        {--output=storage/app/benchmarks/postback-manifest.json : Manifest output path}';

    protected string $description = 'Seed enterprises, providers, and pending transactions for postback benchmark';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $enterpriseCount = (int) $this->option('enterprises');
        $postbackUrl = (string) $this->option('postback-url');
        $outputPath = (string) $this->option('output');

        $this->info("Seeding {$count} transactions across {$enterpriseCount} enterprises...");

        $provider = $this->ensureProvider();
        $enterprises = $this->ensureEnterprises($enterpriseCount, $postbackUrl);
        $transactions = $this->seedTransactions($count, $enterprises, $provider);

        $manifest = [
            'app_base_url' => env('APP_URL', 'http://127.0.0.1:9501'),
            'webhook_endpoint' => '/api/webhooks/firebank',
            'provider' => [
                'id' => $provider->id,
                'code' => $provider->code,
                'webhook_secret' => $provider->webhook_secret ?? 'benchmark-secret-key',
            ],
            'benchmark' => [
                'count' => $count,
                'enterprises' => $enterpriseCount,
                'postback_url' => $postbackUrl,
                'recommended_rate_per_second' => min(333, $count),
            ],
            'transactions' => $transactions,
        ];

        $fullPath = base_path($outputPath);
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $fullPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->info("Manifest written to {$outputPath}");
        $this->info("Seeded {$count} PENDING transactions ready for webhook confirmation.");
        $this->info("Provider: {$provider->code} (id={$provider->id})");

        return 0;
    }

    private function ensureProvider(): PaymentProvider
    {
        return PaymentProvider::firstOrCreate(
            ['code' => 'firebank'],
            [
                'name' => 'Firebank (Benchmark)',
                'is_active' => true,
                'webhook_secret' => 'benchmark-secret-key',
            ],
        );
    }

    private function ensureEnterprises(int $count, string $postbackUrl): array
    {
        $enterprises = [];

        for ($i = 1; $i <= $count; $i++) {
            $enterprise = Enterprise::firstOrCreate(
                ['email' => "bench-enterprise-{$i}@grandepay.test"],
                [
                    'name' => "Benchmark Enterprise {$i}",
                    'document' => str_pad((string) ($i * 1000000), 14, '0', STR_PAD_LEFT),
                    'is_active' => true,
                    'settings' => [
                        'webhook_url' => $postbackUrl,
                        'secret_key' => 'bench-secret-' . $i,
                    ],
                ],
            );

            Wallet::firstOrCreate(
                ['enterprise_id' => $enterprise->id],
                ['balance_cents' => 0, 'blocked_cents' => 0, 'currency' => 'BRL', 'is_active' => true],
            );

            $enterprises[] = $enterprise;
        }

        return $enterprises;
    }

    private function seedTransactions(int $count, array $enterprises, PaymentProvider $provider): array
    {
        $transactions = [];
        $enterpriseCount = count($enterprises);

        DB::beginTransaction();

        try {
            for ($i = 0; $i < $count; $i++) {
                $enterprise = $enterprises[$i % $enterpriseCount];
                $amountCents = random_int(100, 500000);
                $externalId = 'BENCH-' . (string) Str::uuid();

                Transaction::create([
                    'enterprise_id' => $enterprise->id,
                    'payment_provider_id' => $provider->id,
                    'type' => 'cash_in',
                    'status' => Transaction::STATUS_PENDING,
                    'external_id' => $externalId,
                    'amount_cents' => $amountCents,
                    'fee_cents' => 0,
                    'net_amount_cents' => $amountCents,
                    'currency' => 'BRL',
                    'payment_method' => 'pix',
                ]);

                $transactions[] = [
                    'external_id' => $externalId,
                    'provider_transaction_id' => 'PROV-' . (string) Str::uuid(),
                    'end_to_end_id' => 'E2E' . str_pad((string) random_int(1, 999999999), 20, '0', STR_PAD_LEFT),
                    'amount_cents' => $amountCents,
                    'fee_amount' => 0,
                    'final_amount' => $amountCents / 100,
                    'enterprise_id' => $enterprise->id,
                    'counterpart' => [
                        'name' => 'Benchmark Payer ' . ($i + 1),
                        'document' => str_pad((string) random_int(10000000000, 99999999999), 11, '0'),
                    ],
                ];
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $transactions;
    }
}
