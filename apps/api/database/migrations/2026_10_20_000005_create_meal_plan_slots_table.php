<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('scope_key', 40);
            $table->date('planned_for');
            $table->string('meal_type', 12);
            $table->string('dish_type', 12);
            $table->string('dish_name', 180)->nullable();
            $table->unsignedSmallInteger('servings')->default(2);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['scope_key', 'planned_for', 'meal_type'], 'meal_plan_scope_date_meal_unique');
            $table->index(['planned_for', 'is_private', 'owner_user_id']);
            $table->index('recipe_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE meal_plan_slots ADD CONSTRAINT meal_plan_slots_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement("ALTER TABLE meal_plan_slots ADD CONSTRAINT meal_plan_slots_meal_type_check CHECK (meal_type IN ('breakfast', 'lunch', 'dinner'))");
            DB::statement("ALTER TABLE meal_plan_slots ADD CONSTRAINT meal_plan_slots_dish_type_check CHECK ((dish_type = 'free_text' AND dish_name IS NOT NULL AND recipe_id IS NULL) OR (dish_type = 'recipe' AND dish_name IS NULL))");
            DB::statement('ALTER TABLE meal_plan_slots ADD CONSTRAINT meal_plan_slots_servings_check CHECK (servings BETWEEN 1 AND 1000)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_slots');
    }
};
