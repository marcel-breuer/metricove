<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_costs', function (Blueprint $table): void {
            $table->decimal('fuel_liters', 14, 3)->nullable();
            $table->decimal('fuel_unit_price', 14, 4)->nullable();
            $table->decimal('fuel_odometer_value', 20, 3)->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_fuel_category_check CHECK (category = 'fuel' OR (fuel_liters IS NULL AND fuel_unit_price IS NULL AND fuel_odometer_value IS NULL))");
            DB::statement('ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_fuel_pair_check CHECK ((fuel_liters IS NULL) = (fuel_unit_price IS NULL))');
            DB::statement('ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_fuel_values_check CHECK ((fuel_liters IS NULL OR fuel_liters > 0) AND (fuel_unit_price IS NULL OR fuel_unit_price > 0) AND (fuel_odometer_value IS NULL OR fuel_odometer_value >= 0))');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_costs DROP CONSTRAINT IF EXISTS vehicle_costs_fuel_category_check');
            DB::statement('ALTER TABLE vehicle_costs DROP CONSTRAINT IF EXISTS vehicle_costs_fuel_pair_check');
            DB::statement('ALTER TABLE vehicle_costs DROP CONSTRAINT IF EXISTS vehicle_costs_fuel_values_check');
        }

        Schema::table('vehicle_costs', function (Blueprint $table): void {
            $table->dropColumn(['fuel_liters', 'fuel_unit_price', 'fuel_odometer_value']);
        });
    }
};
