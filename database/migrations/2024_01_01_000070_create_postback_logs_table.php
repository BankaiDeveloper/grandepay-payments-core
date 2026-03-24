<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('postback_logs')) {
            return;
        }

        Schema::create('postback_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('enterprise_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('withdrawal_id')->nullable();
            $table->string('event');
            $table->text('url');
            $table->json('payload')->nullable();
            $table->text('signed_payload')->nullable();
            $table->string('signature')->nullable();
            $table->string('status')->default('pending');
            $table->integer('http_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('processing_job_uuid')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at', 'created_at']);
            $table->index(['enterprise_id', 'created_at']);
            $table->index('transaction_id');
            $table->index('withdrawal_id');

            $table->foreign('enterprise_id')->references('id')->on('enterprises');
            $table->foreign('transaction_id')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postback_logs');
    }
};
