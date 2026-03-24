<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS postback_logs_event_transaction_unique ON postback_logs (event, transaction_id) WHERE transaction_id IS NOT NULL AND withdrawal_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS postback_logs_event_withdrawal_unique ON postback_logs (event, withdrawal_id) WHERE withdrawal_id IS NOT NULL AND transaction_id IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS postback_logs_ready_dispatch_idx ON postback_logs (status, next_retry_at, locked_at, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS postback_logs_ready_dispatch_idx');
        DB::statement('DROP INDEX IF EXISTS postback_logs_event_withdrawal_unique');
        DB::statement('DROP INDEX IF EXISTS postback_logs_event_transaction_unique');
    }
};
