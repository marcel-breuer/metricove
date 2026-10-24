<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('reminder_email_enabled')->default(false);
            $table->unsignedSmallInteger('reminder_lead_days')->default(3);
            $table->json('reminder_sources')->default('{"finance":true,"vehicles":true,"utilities":true,"pantry":true}');
        });

        Schema::create('household_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source_module', 32);
            $table->string('source_type', 48);
            $table->unsignedBigInteger('source_id');
            $table->string('occurrence_key', 80);
            $table->date('due_on');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampTz('email_claimed_at')->nullable();
            $table->timestampTz('emailed_at')->nullable();
            $table->boolean('email_requested')->default(false);
            $table->timestampsTz();
            $table->unique(
                ['recipient_user_id', 'source_module', 'source_type', 'source_id', 'occurrence_key'],
                'household_reminders_occurrence_unique',
            );
            $table->index(['recipient_user_id', 'dismissed_at', 'due_on'], 'household_reminders_inbox_index');
            $table->index(['source_module', 'source_type', 'source_id'], 'household_reminders_source_index');
        });

        Schema::table('vehicle_maintenance_records', function (Blueprint $table): void {
            $table->date('next_due_on')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_maintenance_records', function (Blueprint $table): void {
            $table->dropColumn('next_due_on');
        });

        Schema::dropIfExists('household_reminders');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['reminder_email_enabled', 'reminder_lead_days', 'reminder_sources']);
        });
    }
};
