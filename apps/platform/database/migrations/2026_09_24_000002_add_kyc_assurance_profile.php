<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table): void {
            $table->enum('assurance_profile', ['issuer_v1', 'optical_v1'])->default('issuer_v1');
        });
    }

    public function down(): void
    {
        throw new LogicException('Assurance policy history must be retained; roll forward.');
    }
};
