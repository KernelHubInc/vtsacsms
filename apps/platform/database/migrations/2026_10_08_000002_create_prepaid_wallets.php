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
        Schema::create('prepaid_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->string('book', 16);
            $table->char('currency', 3)->default('PHP');
            $table->bigInteger('balance_minor')->default(0);
            $table->bigInteger('reserved_minor')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'subject_id', 'book', 'currency']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
        Schema::create('qr_topups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->string('book', 16);
            $table->char('currency', 3)->default('PHP');
            $table->bigInteger('amount_minor');
            $table->ulid('idempotency_key');
            $table->string('status', 24)->default('creating');
            $table->string('merchant_id', 32)->nullable();
            $table->string('invoice_id', 32)->nullable();
            $table->string('transaction_id', 64)->nullable();
            $table->char('confirmation_hash', 64)->nullable();
            $table->text('qr_content')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'subject_id', 'book', 'idempotency_key'], 'qr_topup_idempotency_uq');
            $table->unique(['tenant_id', 'book', 'transaction_id'], 'qr_topup_transaction_uq');
            $table->index(['tenant_id', 'subject_id', 'book', 'id']);
            $table->index(['tenant_id', 'book', 'status', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
        Schema::create('prepaid_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('account_id');
            $table->ulid('reference_id');
            $table->string('kind', 24);
            $table->bigInteger('amount_minor');
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'account_id', 'reference_id', 'kind'], 'prepaid_entry_reference_uq');
            $table->index(['tenant_id', 'account_id', 'id']);
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('prepaid_accounts')->restrictOnDelete();
        });
        Schema::create('prepaid_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('account_id');
            $table->ulid('reference_id');
            $table->bigInteger('amount_minor');
            $table->bigInteger('spent_minor')->nullable();
            $table->string('status', 16)->default('reserved');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'reference_id']);
            $table->index(['tenant_id', 'account_id', 'status']);
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('prepaid_accounts')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE prepaid_accounts ADD CHECK (balance_minor >= 0 AND reserved_minor >= 0 AND reserved_minor <= balance_minor), ADD CHECK (book IN ('simulated', 'live'))");
            DB::statement('ALTER TABLE qr_topups ADD CHECK (amount_minor > 0)');
            DB::statement('ALTER TABLE prepaid_reservations ADD CHECK (amount_minor > 0 AND (spent_minor IS NULL OR (spent_minor >= 0 AND spent_minor <= amount_minor)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prepaid_reservations');
        Schema::dropIfExists('prepaid_entries');
        Schema::dropIfExists('qr_topups');
        Schema::dropIfExists('prepaid_accounts');
    }
};
