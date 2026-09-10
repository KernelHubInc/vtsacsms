<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('maintenance_work_orders')
            ->where('state', 'acknowledged')
            ->update(['state' => 'triaged']);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE maintenance_work_orders
            ADD CONSTRAINT maintenance_work_orders_state_check
            CHECK (state IN (
                'reported',
                'triaged',
                'planned',
                'scheduled',
                'assigned',
                'in_progress',
                'on_hold',
                'awaiting_parts',
                'awaiting_access',
                'awaiting_external',
                'awaiting_safety_clearance',
                'completed',
                'verification_required',
                'verified',
                'closed',
                'canceled'
            )) NOT VALID
        SQL);
        DB::statement('ALTER TABLE maintenance_work_orders VALIDATE CONSTRAINT maintenance_work_orders_state_check');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE maintenance_work_orders DROP CONSTRAINT IF EXISTS maintenance_work_orders_state_check');
    }
};
