<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chargeback_requests')) {
            return;
        }

        Schema::create('chargeback_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('original_transaction_id');
            $table->unsignedBigInteger('enterprise_id');
            $table->unsignedBigInteger('payment_provider_id')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->unsignedBigInteger('webhook_log_id')->nullable();
            $table->string('source');
            $table->string('execution_mode');
            $table->string('status');
            $table->string('reason_code')->nullable();
            $table->string('idempotency_key');
            $table->string('request_id')->nullable();
            $table->bigInteger('amount_cents');
            $table->text('reason')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('provider_end_to_end_id')->nullable();
            $table->string('provider_status')->nullable();
            $table->integer('provider_fee_cents')->default(0);
            $table->json('provider_response_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('processing_job_uuid')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['original_transaction_id', 'source', 'idempotency_key'],
                'chargeback_requests_txn_source_idem_unique'
            );
            $table->index('enterprise_id');
            $table->index('status');
            $table->index('source');
            $table->index(['enterprise_id', 'status']);

            $table->foreign('original_transaction_id')->references('id')->on('transactions');
            $table->foreign('enterprise_id')->references('id')->on('enterprises');
            $table->foreign('payment_provider_id')->references('id')->on('payment_providers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chargeback_requests');
    }
};
