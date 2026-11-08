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
            $table->boolean('fuel_full_tank')->default(false);
        });

        Schema::table('receipt_documents', function (Blueprint $table): void {
            $table->boolean('fuel_full_tank')->default(false);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_costs_fuel_full_tank_check CHECK (NOT fuel_full_tank OR (category = 'fuel' AND fuel_liters IS NOT NULL AND fuel_odometer_value IS NOT NULL))");
            DB::statement('ALTER TABLE receipt_documents ADD CONSTRAINT receipt_documents_fuel_full_tank_check CHECK (NOT fuel_full_tank OR (fuel_liters IS NOT NULL AND fuel_odometer_value IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_costs DROP CONSTRAINT IF EXISTS vehicle_costs_fuel_full_tank_check');
            DB::statement('ALTER TABLE receipt_documents DROP CONSTRAINT IF EXISTS receipt_documents_fuel_full_tank_check');
        }

        Schema::table('vehicle_costs', function (Blueprint $table): void {
            $table->dropColumn('fuel_full_tank');
        });
        Schema::table('receipt_documents', function (Blueprint $table): void {
            $table->dropColumn('fuel_full_tank');
        });
    }
};
