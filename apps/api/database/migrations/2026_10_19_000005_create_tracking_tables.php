<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_private')->default(true);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
        });

        Schema::create('tracker_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_definition_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 26);
            $table->string('label', 120);
            $table->string('data_type', 16);
            $table->string('unit', 32)->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('minimum', 20, 6)->nullable();
            $table->decimal('maximum', 20, 6)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('created_version')->default(1);
            $table->unsignedSmallInteger('retired_version')->nullable();
            $table->timestampsTz();
            $table->unique(['tracker_definition_id', 'field_key']);
            $table->index(['tracker_definition_id', 'retired_version', 'sort_order'], 'tracker_fields_active_order_index');
        });

        Schema::create('tracker_schema_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('schema_snapshot');
            $table->timestampsTz();
            $table->unique(['tracker_definition_id', 'version']);
        });

        Schema::create('tracker_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version');
            $table->timestampTz('recorded_at');
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index(['tracker_definition_id', 'recorded_at', 'id'], 'tracker_entries_history_index');
            $table->index(['owner_user_id', 'tracker_definition_id']);
        });

        Schema::create('tracker_entry_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracker_field_id')->constrained()->cascadeOnDelete();
            $table->string('field_key_snapshot', 26);
            $table->string('field_label_snapshot', 120);
            $table->string('data_type_snapshot', 16);
            $table->string('unit_snapshot', 32)->nullable();
            $table->decimal('decimal_value', 20, 6)->nullable();
            $table->text('text_value')->nullable();
            $table->date('date_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->timestampsTz();
            $table->unique(['tracker_entry_id', 'tracker_field_id'], 'tracker_entry_values_entry_field_unique');
            $table->index(['tracker_field_id', 'decimal_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_entry_values');
        Schema::dropIfExists('tracker_entries');
        Schema::dropIfExists('tracker_schema_revisions');
        Schema::dropIfExists('tracker_fields');
        Schema::dropIfExists('tracker_definitions');
    }
};
