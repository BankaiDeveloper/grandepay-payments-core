<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallets')) {
            return;
        }

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enterprise_id')->unique();
            $table->bigInteger('balance_cents')->default(0);
            $table->bigInteger('blocked_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->foreign('enterprise_id')->references('id')->on('enterprises');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
