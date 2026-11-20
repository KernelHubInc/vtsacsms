<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('support_level')->default(2);
            $table->index(['tenant_id', 'support_level', 'status'], 'support_ticket_level_idx');
        });
        Schema::create('support_ticket_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('ticket_id');
            $table->unsignedSmallInteger('position');
            $table->string('mime_type', 20);
            $table->unsignedInteger('size_bytes');
            $table->longText('content')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'ticket_id', 'position'], 'support_image_position_uq');
            $table->foreign(['tenant_id', 'ticket_id'], 'support_image_ticket_fk')
                ->references(['tenant_id', 'id'])->on('support_tickets')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_tickets ADD CONSTRAINT support_level_range CHECK (support_level BETWEEN 1 AND 3)');
            DB::statement("ALTER TABLE support_ticket_images ADD CONSTRAINT support_image_bounds CHECK (\"position\" BETWEEN 0 AND 4 AND size_bytes BETWEEN 1 AND 2097152 AND mime_type IN ('image/jpeg', 'image/png'))");
        }
    }

    public function down(): void
    {
        // Retain accepted customer evidence and routing during a code rollback.
    }
};
