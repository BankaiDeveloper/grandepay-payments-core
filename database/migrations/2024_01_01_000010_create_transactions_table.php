<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transactions')) {
            return;
        }

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('enterprise_id');
            $table->unsignedBigInteger('payment_provider_id')->nullable();
            $table->unsignedBigInteger('provider_route_id')->nullable();
            $table->string('routing_operation')->nullable();
            $table->string('type')->default('cash_in');
            $table->smallInteger('status')->default(0);
            $table->string('idempotency_key')->nullable();
            $table->string('request_id')->nullable();
            $table->string('external_id')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->bigInteger('fee_cents')->default(0);
            $table->bigInteger('net_amount_cents')->default(0);
            $table->bigInteger('refunded_amount_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('payment_method')->default('pix');
            $table->string('pix_key')->nullable();
            $table->string('pix_key_type')->nullable();
            $table->text('pix_code')->nullable();
            $table->timestamp('pix_expiration')->nullable();
            $table->text('description')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_document')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_document')->nullable();
            $table->json('provider_raw_response')->nullable();
            $table->json('provider_raw_webhook')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('enterprise_id');
            $table->index('payment_provider_id');
            $table->index('status');
            $table->index('type');
            $table->index('external_id');
            $table->index('provider_transaction_id');
            $table->index('end_to_end_id');
            $table->index(['enterprise_id', 'idempotency_key']);

            $table->foreign('enterprise_id')->references('id')->on('enterprises');
            $table->foreign('payment_provider_id')->references('id')->on('payment_providers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
