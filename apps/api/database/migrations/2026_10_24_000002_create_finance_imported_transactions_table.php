<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_imported_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('source_fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['user_id', 'source_fingerprint'], 'finance_imported_user_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_imported_transactions');
    }
};
