<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('priority', 8)->default('medium');
            $table->string('recurrence_frequency', 8)->nullable();
            $table->unsignedSmallInteger('recurrence_interval')->default(1);
            $table->boolean('is_completed')->default(false);
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('occurrence_number')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id', 'is_completed', 'due_at']);
            $table->index(['assignee_user_id', 'is_completed', 'due_at']);
            $table->index(['updated_at', 'id']);
        });

        Schema::create('household_task_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('household_tasks')->cascadeOnDelete();
            $table->unsignedInteger('occurrence_number');
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at');
            $table->unique(['task_id', 'occurrence_number']);
            $table->index(['task_id', 'completed_at', 'id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE household_tasks ADD CONSTRAINT household_tasks_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement("ALTER TABLE household_tasks ADD CONSTRAINT household_tasks_priority_check CHECK (priority IN ('low', 'medium', 'high'))");
            DB::statement("ALTER TABLE household_tasks ADD CONSTRAINT household_tasks_recurrence_check CHECK ((recurrence_frequency IS NULL AND recurrence_interval = 1) OR (recurrence_frequency IN ('daily', 'weekly', 'monthly', 'yearly') AND due_at IS NOT NULL AND recurrence_interval BETWEEN 1 AND 99))");
            DB::statement('ALTER TABLE household_tasks ADD CONSTRAINT household_tasks_completed_state_check CHECK ((is_completed AND recurrence_frequency IS NULL AND completed_at IS NOT NULL) OR (NOT is_completed AND completed_at IS NULL))');
            DB::statement('ALTER TABLE household_tasks ADD CONSTRAINT household_tasks_version_check CHECK (version >= 1 AND occurrence_number >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('household_task_completions');
        Schema::dropIfExists('household_tasks');
    }
};
