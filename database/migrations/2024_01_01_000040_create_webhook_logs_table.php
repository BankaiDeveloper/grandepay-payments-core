<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_logs')) {
            return;
        }

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('payment_provider_id');
            $table->unsignedBigInteger('enterprise_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('withdrawal_id')->nullable();
            $table->string('event_type')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->string('idempotency_key');
            $table->string('ip_address')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->text('raw_body')->nullable();
            $table->string('request_path')->nullable();
            $table->string('request_method', 10)->nullable();
            $table->integer('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->boolean('processed')->default(false);
            $table->string('processing_status')->default('received');
            $table->unsignedInteger('attempts_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->text('signature_error')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('processing_job_uuid')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['payment_provider_id', 'idempotency_key'],
                'webhook_logs_provider_idempotency_unique'
            );
            $table->index('payment_provider_id');
            $table->index('enterprise_id');
            $table->index('transaction_id');
            $table->index('processing_status');
            $table->index('processed');

            $table->foreign('payment_provider_id')->references('id')->on('payment_providers');
            $table->foreign('transaction_id')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
