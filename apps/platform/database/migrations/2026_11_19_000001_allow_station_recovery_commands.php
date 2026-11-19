<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_commands', function (Blueprint $table): void {
            $table->ulid('session_id')->nullable()->change();
            $table->ulid('connector_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Station commands have no session. Retain the compatible nullable columns on rollback.
    }
};
