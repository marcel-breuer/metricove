<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_task_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('household_tasks')->cascadeOnDelete();
            $table->string('title', 180);
            $table->unsignedSmallInteger('position')->default(0);
            $table->integer('due_offset_days')->nullable();
            $table->time('due_time')->nullable();
            $table->unsignedInteger('reminder_lead_minutes')->default(0);
            $table->timestampsTz();
            $table->index(['task_id', 'position', 'id']);
        });

        Schema::create('household_task_checklist_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('household_tasks')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained('household_task_checklist_items')->nullOnDelete();
            $table->unsignedInteger('task_occurrence_number');
            $table->string('title_snapshot', 180);
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['checklist_item_id', 'task_occurrence_number'], 'task_checklist_item_occurrence_unique');
            $table->index(['task_id', 'task_occurrence_number', 'completed_at'], 'task_checklist_occurrence_state_index');
            $table->index(['due_at', 'completed_at'], 'task_checklist_occurrence_due_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE household_task_checklist_items ADD CONSTRAINT task_checklist_due_pair_check CHECK ((due_offset_days IS NULL AND due_time IS NULL) OR due_time IS NOT NULL)');
            DB::statement('ALTER TABLE household_task_checklist_items ADD CONSTRAINT task_checklist_lead_check CHECK (reminder_lead_minutes <= 43200)');
            DB::statement('ALTER TABLE household_task_checklist_occurrences ADD CONSTRAINT task_checklist_completion_state_check CHECK ((completed_at IS NULL AND completed_by_user_id IS NULL) OR completed_at IS NOT NULL)');
            DB::statement('ALTER TABLE household_task_checklist_occurrences ADD CONSTRAINT task_checklist_occurrence_number_check CHECK (task_occurrence_number >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('household_task_checklist_occurrences');
        Schema::dropIfExists('household_task_checklist_items');
    }
};
