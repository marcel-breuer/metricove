<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('title', 180);
            $table->string('category', 80)->nullable();
            $table->text('instructions');
            $table->unsignedSmallInteger('servings');
            $table->unsignedSmallInteger('preparation_minutes')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['category', 'title']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 120);
            $table->decimal('quantity', 14, 4);
            $table->string('unit', 40);
            $table->string('note', 160)->nullable();
            $table->unique(['recipe_id', 'position']);
        });

        Schema::create('recipe_favorites', function (Blueprint $table): void {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['recipe_id', 'user_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_servings_check CHECK (servings BETWEEN 1 AND 1000)');
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_preparation_minutes_check CHECK (preparation_minutes IS NULL OR preparation_minutes BETWEEN 1 AND 1440)');
            DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_favorites');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
