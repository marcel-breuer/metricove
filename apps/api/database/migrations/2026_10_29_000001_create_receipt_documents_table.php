<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_private')->default(true);
            $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->foreignId('vehicle_cost_id')->nullable()->constrained('vehicle_costs')->nullOnDelete();
            $table->string('file_path', 255)->unique();
            $table->string('mime_type', 32);
            $table->string('merchant', 200)->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->date('invoice_date');
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->decimal('tax_amount', 18, 2)->nullable();
            $table->uuid('idempotency_key');
            $table->char('file_digest', 64);
            $table->char('request_fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['owner_user_id', 'idempotency_key'], 'receipt_documents_owner_idempotency_unique');
            $table->index(['owner_user_id', 'created_at'], 'receipt_documents_owner_created_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE receipt_documents ADD CONSTRAINT receipt_documents_target_check CHECK (financial_transaction_id IS NULL OR vehicle_cost_id IS NULL)');
            DB::statement('ALTER TABLE receipt_documents ADD CONSTRAINT receipt_documents_amount_check CHECK (amount >= 0 AND (tax_amount IS NULL OR tax_amount >= 0))');
            DB::statement("ALTER TABLE receipt_documents ADD CONSTRAINT receipt_documents_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE receipt_documents ADD CONSTRAINT receipt_documents_mime_type_check CHECK (mime_type IN ('application/pdf', 'image/jpeg', 'image/png'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_documents');
    }
};
