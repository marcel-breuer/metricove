<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('meter_type', 16);
            $table->string('unit', 8);
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(true);
            $table->decimal('alert_threshold', 20, 6)->nullable();
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['meter_type', 'name']);
            $table->index('owner_user_id');
            $table->index('created_by_user_id');
        });

        Schema::create('utility_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_meter_id')->constrained('utility_meters')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->date('reading_date');
            $table->decimal('value', 20, 6);
            $table->text('note')->nullable();
            $table->text('photo_path')->nullable();
            $table->timestampsTz();
            $table->unique(['utility_meter_id', 'reading_date'], 'utility_readings_meter_date_unique');
            $table->index(['utility_meter_id', 'reading_date', 'id'], 'utility_readings_history_index');
            $table->index(['is_private', 'owner_user_id']);
            $table->index('owner_user_id');
            $table->index('created_by_user_id');
        });

        Schema::create('utility_reading_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_reading_id')->constrained('utility_readings')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('old_reading_date');
            $table->date('new_reading_date');
            $table->decimal('old_value', 20, 6);
            $table->decimal('new_value', 20, 6);
            $table->text('old_note')->nullable();
            $table->text('new_note')->nullable();
            $table->text('reason');
            $table->timestampsTz();
            $table->index(['utility_reading_id', 'created_at', 'id'], 'utility_reading_revisions_history_index');
            $table->index('changed_by_user_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE utility_meters ADD CONSTRAINT utility_meters_type_unit_check CHECK ((meter_type = 'electricity' AND unit = 'kWh') OR (meter_type IN ('gas', 'water') AND unit = 'm³'))");
            DB::statement('ALTER TABLE utility_meters ADD CONSTRAINT utility_meters_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE utility_meters ADD CONSTRAINT utility_meters_threshold_nonnegative_check CHECK (alert_threshold IS NULL OR alert_threshold >= 0)');
            DB::statement('ALTER TABLE utility_readings ADD CONSTRAINT utility_readings_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE utility_readings ADD CONSTRAINT utility_readings_value_nonnegative_check CHECK (value >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_reading_revisions');
        Schema::dropIfExists('utility_readings');
        Schema::dropIfExists('utility_meters');
    }
};
