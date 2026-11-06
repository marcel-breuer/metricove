<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->string('source_system', 40)->nullable();
            $table->string('source_id', 180)->nullable();
            $table->unique(['owner_user_id', 'source_system', 'source_id'], 'recipes_source_reference_unique');
        });

        Schema::table('meal_plan_slots', function (Blueprint $table): void {
            $table->string('source_system', 40)->nullable();
            $table->string('source_id', 180)->nullable();
            $table->unique(['owner_user_id', 'source_system', 'source_id'], 'meal_plan_slots_source_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_slots', function (Blueprint $table): void {
            $table->dropUnique('meal_plan_slots_source_reference_unique');
            $table->dropColumn(['source_system', 'source_id']);
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropUnique('recipes_source_reference_unique');
            $table->dropColumn(['source_system', 'source_id']);
        });
    }
};
