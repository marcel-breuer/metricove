<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pantry_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('name', 180);
            $table->decimal('quantity', 14, 3)->default('0.000');
            $table->string('unit', 32);
            $table->string('location', 120)->nullable();
            $table->date('best_before_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['best_before_on', 'id']);
        });

        Schema::create('pantry_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pantry_item_id')->constrained('pantry_items')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('movement_type', 12);
            $table->decimal('quantity_before', 14, 3);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('quantity_after', 14, 3);
            $table->string('unit', 32);
            $table->string('note', 500)->nullable();
            $table->timestampsTz();
            $table->index(['pantry_item_id', 'created_at', 'id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pantry_items ADD CONSTRAINT pantry_items_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE pantry_items ADD CONSTRAINT pantry_items_quantity_check CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE pantry_items ADD CONSTRAINT pantry_items_version_check CHECK (version >= 1)');
            DB::statement("ALTER TABLE pantry_stock_movements ADD CONSTRAINT pantry_stock_movements_type_check CHECK (movement_type IN ('initial', 'adjustment'))");
            DB::statement('ALTER TABLE pantry_stock_movements ADD CONSTRAINT pantry_stock_movements_quantity_check CHECK (quantity_before >= 0 AND quantity_after >= 0 AND quantity_delta <> 0 AND quantity_before + quantity_delta = quantity_after)');
            DB::statement("ALTER TABLE pantry_stock_movements ADD CONSTRAINT pantry_stock_movements_initial_check CHECK (movement_type <> 'initial' OR (quantity_before = 0 AND quantity_delta > 0))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pantry_stock_movements');
        Schema::dropIfExists('pantry_items');
    }
};
