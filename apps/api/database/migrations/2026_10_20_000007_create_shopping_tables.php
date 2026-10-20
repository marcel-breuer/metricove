<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('name', 160);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['updated_at', 'id']);
        });

        Schema::create('shopping_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 180);
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_checked')->default(false);
            $table->timestampTz('checked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['shopping_list_id', 'is_checked', 'sort_order', 'id']);
        });

        Schema::create('shopping_list_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->foreignId('shopping_item_id')->nullable()->constrained('shopping_items')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 24);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestampsTz();
            $table->index(['shopping_list_id', 'created_at', 'id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_version_check CHECK (version >= 1)');
            DB::statement('ALTER TABLE shopping_items ADD CONSTRAINT shopping_items_quantity_check CHECK (quantity IS NULL OR quantity > 0)');
            DB::statement('ALTER TABLE shopping_items ADD CONSTRAINT shopping_items_quantity_unit_check CHECK ((quantity IS NULL AND unit IS NULL) OR (quantity IS NOT NULL AND unit IS NOT NULL))');
            DB::statement('ALTER TABLE shopping_items ADD CONSTRAINT shopping_items_version_check CHECK (version >= 1 AND sort_order >= 0)');
            DB::statement('ALTER TABLE shopping_items ADD CONSTRAINT shopping_items_checked_state_check CHECK ((is_checked AND checked_at IS NOT NULL) OR (NOT is_checked AND checked_at IS NULL))');
            DB::statement("ALTER TABLE shopping_list_events ADD CONSTRAINT shopping_list_events_type_check CHECK (event_type IN ('list_created', 'list_renamed', 'item_added', 'item_updated', 'item_checked', 'item_unchecked', 'item_deleted', 'item_reordered'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_events');
        Schema::dropIfExists('shopping_items');
        Schema::dropIfExists('shopping_lists');
    }
};
