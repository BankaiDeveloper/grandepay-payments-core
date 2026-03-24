<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('withdrawals')) {
            return;
        }

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('enterprise_id');
            $table->unsignedBigInteger('payment_provider_id')->nullable();
            $table->unsignedBigInteger('provider_route_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->smallInteger('status')->default(0);
            $table->string('idempotency_key')->nullable();
            $table->string('external_id')->nullable();
            $table->string('provider_withdrawal_id')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->bigInteger('fee_cents')->default(0);
            $table->bigInteger('net_amount_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('pix_key')->nullable();
            $table->string('pix_key_type')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_document')->nullable();
            $table->text('description')->nullable();
            $table->json('provider_raw_response')->nullable();
            $table->json('provider_raw_webhook')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('enterprise_id');
            $table->index('payment_provider_id');
            $table->index('status');
            $table->index('external_id');
            $table->index('provider_withdrawal_id');
            $table->index('end_to_end_id');
            $table->index(['enterprise_id', 'status', 'created_at']);
            $table->index(['enterprise_id', 'created_at']);
            $table->index(['status', 'created_at']);

            $table->foreign('enterprise_id')->references('id')->on('enterprises');
            $table->foreign('payment_provider_id')->references('id')->on('payment_providers');
            $table->foreign('transaction_id')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
