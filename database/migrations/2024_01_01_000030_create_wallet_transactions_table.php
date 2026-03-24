<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_transactions')) {
            return;
        }

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('wallet_id');
            $table->unsignedBigInteger('enterprise_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('withdrawal_id')->nullable();
            $table->unsignedBigInteger('webhook_log_id')->nullable();
            $table->string('type');
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_before_cents');
            $table->bigInteger('balance_after_cents');
            $table->text('description')->nullable();
            $table->string('provider_code')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->timestamps();

            $table->index('wallet_id');
            $table->index('enterprise_id');
            $table->index('transaction_id');
            $table->unique('webhook_log_id', 'wallet_transactions_webhook_log_id_unique');

            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->foreign('enterprise_id')->references('id')->on('enterprises');
            $table->foreign('transaction_id')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
