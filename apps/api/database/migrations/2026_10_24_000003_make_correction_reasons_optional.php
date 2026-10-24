<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_reading_revisions', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
        });

        Schema::table('vehicle_odometer_revisions', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('utility_reading_revisions')->whereNull('reason')->update(['reason' => '']);
        DB::table('vehicle_odometer_revisions')->whereNull('reason')->update(['reason' => '']);

        Schema::table('utility_reading_revisions', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
        });

        Schema::table('vehicle_odometer_revisions', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
        });
    }
};
