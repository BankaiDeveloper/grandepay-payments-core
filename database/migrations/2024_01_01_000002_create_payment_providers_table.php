<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_providers')) {
            return;
        }

        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->string('base_url')->nullable();
            $table->text('client_key')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('api_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
