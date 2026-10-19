<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_audit_events', function (Blueprint $table) {
            $table->id();
            // Keep numeric IDs after account removal so history remains attributable.
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('subject_user_id')->index();
            $table->string('action', 64);
            $table->json('details')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_audit_events');
    }
};
