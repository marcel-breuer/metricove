<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('make', 80);
            $table->string('model', 80);
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('fuel_type', 20);
            $table->string('odometer_unit', 2)->default('km');
            $table->string('description', 2000)->nullable();
            $table->boolean('is_private')->default(true);
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['make', 'model']);
            $table->index('created_by_user_id');
        });

        Schema::create('vehicle_odometer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->date('reading_date');
            $table->decimal('value', 20, 3);
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['vehicle_id', 'reading_date'], 'vehicle_odometer_vehicle_date_unique');
            $table->index(['vehicle_id', 'reading_date', 'id'], 'vehicle_odometer_history_index');
            $table->index(['is_private', 'owner_user_id']);
            $table->index('owner_user_id');
        });

        Schema::create('vehicle_odometer_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_odometer_reading_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('old_reading_date');
            $table->date('new_reading_date');
            $table->decimal('old_value', 20, 3);
            $table->decimal('new_value', 20, 3);
            $table->text('old_note')->nullable();
            $table->text('new_note')->nullable();
            $table->text('reason');
            $table->timestampsTz();
            $table->index(['vehicle_odometer_reading_id', 'created_at', 'id'], 'vehicle_odometer_revisions_history_index');
        });

        Schema::create('vehicle_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('title', 160);
            $table->string('service_type', 40);
            $table->date('occurred_on');
            $table->decimal('odometer_value', 20, 3)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index(['vehicle_id', 'occurred_on', 'id'], 'vehicle_maintenance_history_index');
            $table->index(['is_private', 'owner_user_id']);
        });

        Schema::create('vehicle_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('provider', 120);
            $table->string('coverage_type', 40);
            $table->date('valid_from');
            $table->date('valid_until');
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index(['vehicle_id', 'valid_until', 'id'], 'vehicle_insurance_expiry_index');
            $table->index(['is_private', 'owner_user_id']);
        });

        Schema::create('vehicle_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('category', 24);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->date('period_start');
            $table->date('period_end')->nullable();
            $table->string('period', 16)->default('once');
            $table->string('description', 500)->nullable();
            $table->timestampsTz();
            $table->index(['vehicle_id', 'period_start', 'id'], 'vehicle_costs_period_index');
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['currency', 'category']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_fuel_type_check CHECK (fuel_type IN ('petrol', 'diesel', 'hybrid', 'electric', 'lpg', 'other'))");
            DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_odometer_unit_check CHECK (odometer_unit IN ('km', 'mi'))");
            DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_model_year_check CHECK (model_year IS NULL OR model_year BETWEEN 1886 AND 2200)');
            DB::statement('ALTER TABLE vehicle_odometer_readings ADD CONSTRAINT vehicle_odometer_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE vehicle_odometer_readings ADD CONSTRAINT vehicle_odometer_nonnegative_check CHECK (value >= 0)');
            DB::statement('ALTER TABLE vehicle_odometer_revisions ADD CONSTRAINT vehicle_odometer_revisions_nonnegative_check CHECK (old_value >= 0 AND new_value >= 0)');
            DB::statement('ALTER TABLE vehicle_maintenance_records ADD CONSTRAINT vehicle_maintenance_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE vehicle_maintenance_records ADD CONSTRAINT vehicle_maintenance_odometer_nonnegative_check CHECK (odometer_value IS NULL OR odometer_value >= 0)');
            DB::statement('ALTER TABLE vehicle_insurance_policies ADD CONSTRAINT vehicle_insurance_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE vehicle_insurance_policies ADD CONSTRAINT vehicle_insurance_dates_check CHECK (valid_until >= valid_from)');
            DB::statement('ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_amount_nonnegative_check CHECK (amount >= 0)');
            DB::statement("ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_period_check CHECK (period IN ('once', 'monthly', 'quarterly', 'yearly'))");
            DB::statement('ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_period_dates_check CHECK (period_end IS NULL OR period_end >= period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_costs');
        Schema::dropIfExists('vehicle_insurance_policies');
        Schema::dropIfExists('vehicle_maintenance_records');
        Schema::dropIfExists('vehicle_odometer_revisions');
        Schema::dropIfExists('vehicle_odometer_readings');
        Schema::dropIfExists('vehicles');
    }
};
