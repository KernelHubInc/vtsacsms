<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_email_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('session_id');
            $table->ulid('subject_id');
            $table->json('summary');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('claimed_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'session_id']);
            $table->index(['tenant_id', 'sent_at', 'available_at']);
            $table->foreign(['tenant_id', 'session_id'])
                ->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_email_deliveries');
    }
};
