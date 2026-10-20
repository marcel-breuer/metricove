<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_context_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 16); // user or project
            $table->string('project_key', 120)->nullable();
            $table->string('kind', 40);
            $table->text('content');
            $table->string('source', 120)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['owner_user_id', 'scope', 'project_key', 'enabled'], 'ai_context_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_context_entries');
    }
};
