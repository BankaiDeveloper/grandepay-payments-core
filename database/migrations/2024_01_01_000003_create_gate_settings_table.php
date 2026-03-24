<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gate_settings')) {
            return;
        }

        Schema::create('gate_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->default('default');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_settings');
    }
};
