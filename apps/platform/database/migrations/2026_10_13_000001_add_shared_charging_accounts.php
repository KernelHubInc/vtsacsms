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
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('charging_account_type', 16)->nullable();
            $table->ulid('owner_subject_id')->nullable();
            $table->ulid('personal_subject_id')->nullable();
            $table->unique(['tenant_id', 'personal_subject_id'], 'charging_personal_account_unique');
            $table->index(['tenant_id', 'charging_account_type']);
        });
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('tenant');
            $table->index(['tenant_id', 'purpose', 'organization_id']);
        });
        Schema::table('driver_garages', function (Blueprint $table): void {
            $table->ulid('subject_id')->nullable()->change();
            $table->ulid('charging_account_id')->nullable();
            $table->unique(['tenant_id', 'charging_account_id']);
        });
        Schema::create('driver_vehicle_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('vehicle_id');
            $table->ulid('subject_id');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'vehicle_id', 'subject_id']);
            $table->index(['tenant_id', 'subject_id']);
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('driver_vehicles')->cascadeOnDelete();
        });
        Schema::table('prepaid_accounts', function (Blueprint $table): void {
            $table->ulid('subject_id')->nullable()->change();
            $table->ulid('charging_account_id')->nullable();
            $table->unique(['tenant_id', 'charging_account_id', 'book', 'currency'], 'prepaid_charging_account_unique');
        });
        Schema::table('qr_topups', function (Blueprint $table): void {
            $table->ulid('charging_account_id')->nullable();
            $table->index(['tenant_id', 'charging_account_id', 'book', 'id']);
        });
        Schema::create('prepaid_spending_limits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('charging_account_id');
            $table->string('target_type', 16);
            $table->ulid('target_id');
            $table->unsignedBigInteger('amount_minor');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'charging_account_id', 'target_type', 'target_id'], 'prepaid_limit_target_unique');
        });
        foreach (['charging_quotes', 'charging_customer_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->ulid('charging_account_id')->nullable();
                $table->ulid('vehicle_id')->nullable();
                $table->ulid('payer_account_id')->nullable();
                $table->ulid('prepaid_account_id')->nullable();
                $table->index(['tenant_id', 'charging_account_id', 'id']);
            });
        }
        foreach (['driver_garages', 'prepaid_accounts', 'qr_topups', 'prepaid_spending_limits', 'charging_quotes', 'charging_customer_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->foreign(['tenant_id', 'charging_account_id'], $name.'_account_tenant_fk')
                    ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['driver_garages', 'prepaid_accounts'] as $name) {
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_owner_check CHECK ((subject_id IS NULL) <> (charging_account_id IS NULL))");
            }
            DB::statement("ALTER TABLE prepaid_spending_limits ADD CONSTRAINT prepaid_limit_valid_check CHECK (target_type IN ('driver', 'vehicle') AND amount_minor >= 0)");
        }
    }

    public function down(): void
    {
        // Only an unused schema can be rolled back without erasing ownership or financial evidence.
        if (DB::table('organizations')->whereNotNull('charging_account_type')->exists()) {
            throw new RuntimeException('Charging accounts exist. Preserve their attribution and roll forward.');
        }
        foreach (['driver_garages', 'prepaid_accounts', 'qr_topups', 'prepaid_spending_limits', 'charging_quotes', 'charging_customer_sessions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign(
                DB::getDriverName() === 'sqlite' ? ['tenant_id', 'charging_account_id'] : $name.'_account_tenant_fk',
            ));
        }
        Schema::dropIfExists('prepaid_spending_limits');
        Schema::dropIfExists('driver_vehicle_assignments');
        foreach (['charging_quotes', 'charging_customer_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['tenant_id', 'charging_account_id', 'id']);
                $table->dropColumn(['charging_account_id', 'vehicle_id', 'payer_account_id', 'prepaid_account_id']);
            });
        }
        foreach (['driver_garages', 'prepaid_accounts'] as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT {$name}_owner_check");
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique($name === 'prepaid_accounts' ? 'prepaid_charging_account_unique' : ['tenant_id', 'charging_account_id']);
                $table->dropColumn('charging_account_id');
                $table->ulid('subject_id')->nullable(false)->change();
            });
        }
        Schema::table('qr_topups', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'charging_account_id', 'book', 'id']);
            $table->dropColumn('charging_account_id');
        });
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'purpose', 'organization_id']);
            $table->dropColumn('purpose');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique('charging_personal_account_unique');
            $table->dropIndex(['tenant_id', 'charging_account_type']);
            $table->dropColumn(['charging_account_type', 'owner_subject_id', 'personal_subject_id']);
        });
    }
};
