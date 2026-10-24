<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_tasks', function (Blueprint $table): void {
            $table->date('recurrence_anchor_date')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('UPDATE household_tasks SET recurrence_anchor_date = (due_at AT TIME ZONE timezone)::date WHERE recurrence_frequency IS NOT NULL');
            DB::statement('ALTER TABLE household_tasks DROP CONSTRAINT household_tasks_recurrence_check');
            DB::statement("ALTER TABLE household_tasks ADD CONSTRAINT household_tasks_recurrence_check CHECK ((recurrence_frequency IS NULL AND recurrence_interval = 1 AND recurrence_anchor_date IS NULL) OR (recurrence_frequency IN ('daily', 'weekly', 'monthly', 'yearly') AND due_at IS NOT NULL AND recurrence_interval BETWEEN 1 AND 99 AND recurrence_anchor_date IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE household_tasks DROP CONSTRAINT household_tasks_recurrence_check');
            DB::statement("ALTER TABLE household_tasks ADD CONSTRAINT household_tasks_recurrence_check CHECK ((recurrence_frequency IS NULL AND recurrence_interval = 1) OR (recurrence_frequency IN ('daily', 'weekly', 'monthly', 'yearly') AND due_at IS NOT NULL AND recurrence_interval BETWEEN 1 AND 99))");
        }

        Schema::table('household_tasks', function (Blueprint $table): void {
            $table->dropColumn('recurrence_anchor_date');
        });
    }
};
