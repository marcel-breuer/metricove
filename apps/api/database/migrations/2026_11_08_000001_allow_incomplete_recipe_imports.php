<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->boolean('needs_review')->default(false);
            $table->unsignedSmallInteger('servings')->nullable()->change();
        });
        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->decimal('quantity', 14, 4)->nullable()->change();
            $table->string('unit', 40)->nullable()->change();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_unit_check CHECK ((quantity IS NULL AND unit IS NULL) OR (quantity IS NOT NULL AND unit IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT IF EXISTS recipe_ingredients_quantity_unit_check');
        }
        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->decimal('quantity', 14, 4)->nullable(false)->change();
            $table->string('unit', 40)->nullable(false)->change();
        });
        Schema::table('recipes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('servings')->nullable(false)->change();
            $table->dropColumn('needs_review');
        });
    }
};
