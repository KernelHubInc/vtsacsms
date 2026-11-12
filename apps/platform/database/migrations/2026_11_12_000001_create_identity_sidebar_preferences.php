<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_sidebar_preferences', function (Blueprint $table): void {
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->string('panel', 16);
            $table->json('layout');
            $table->timestampsTz();

            $table->primary(['tenant_id', 'subject_id', 'panel']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subject_id')->references('public_id')->on('users')->cascadeOnDelete();
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_sidebar_preferences');
    }
};
