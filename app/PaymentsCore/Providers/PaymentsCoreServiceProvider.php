<?php

declare(strict_types=1);

namespace App\PaymentsCore\Providers;

use App\PaymentsCore\Domain\Contracts\AffiliateCommissionServiceInterface;
use App\PaymentsCore\Domain\Contracts\PostbackServiceInterface;
use App\PaymentsCore\Domain\Stubs\NullAffiliateCommissionService;
use App\PaymentsCore\Infrastructure\Services\ChargebackRequestService;
use App\PaymentsCore\Infrastructure\Services\EnterpriseWithdrawalModeResolver;
use App\PaymentsCore\Infrastructure\Services\PixFeeService;
use App\PaymentsCore\Infrastructure\Services\PostbackService;
use App\PaymentsCore\Infrastructure\Services\ProviderWebhookMapper;
use App\PaymentsCore\Infrastructure\Services\WalletOperationService;
use App\PaymentsCore\Infrastructure\Services\WalletService;
use App\PaymentsCore\Infrastructure\Services\WebhookFinancialStateService;
use App\PaymentsCore\Infrastructure\Services\WebhookLogService;
use App\PaymentsCore\Infrastructure\Services\WithdrawalService;
use Hypervel\Support\ServiceProvider;

class PaymentsCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PostbackServiceInterface::class, PostbackService::class);
        $this->app->bind(AffiliateCommissionServiceInterface::class, NullAffiliateCommissionService::class);

        $this->app->bind(WebhookFinancialStateService::class, function ($app) {
            return new WebhookFinancialStateService(
                $app->get(WalletService::class),
                $app->get(PostbackServiceInterface::class),
                $app->get(AffiliateCommissionServiceInterface::class),
            );
        });

        $this->app->bind(WalletOperationService::class, function ($app) {
            return new WalletOperationService(
                $app->get(WebhookFinancialStateService::class),
                $app->get(PixFeeService::class),
            );
        });

        $this->app->bind(WithdrawalService::class, function ($app) {
            return new WithdrawalService(
                $app->get(WalletService::class),
                $app->get(EnterpriseWithdrawalModeResolver::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations'));
    }
}
