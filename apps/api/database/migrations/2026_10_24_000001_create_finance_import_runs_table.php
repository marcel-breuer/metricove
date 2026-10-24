<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('created_count');
            $table->unsignedSmallInteger('skipped_count');
            $table->timestampsTz();
            $table->unique(['user_id', 'idempotency_key'], 'finance_import_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_import_runs');
    }
};
