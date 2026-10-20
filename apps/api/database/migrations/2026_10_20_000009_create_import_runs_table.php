<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utility_meter_id')->constrained('utility_meters')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->char('file_sha256', 64);
            $table->unsignedSmallInteger('created_count');
            $table->unsignedSmallInteger('skipped_count');
            $table->timestampsTz();
            $table->unique(['user_id', 'idempotency_key'], 'import_runs_user_idempotency_unique');
            $table->index(['utility_meter_id', 'created_at'], 'import_runs_meter_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
