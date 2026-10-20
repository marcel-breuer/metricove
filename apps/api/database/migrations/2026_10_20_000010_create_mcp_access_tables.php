<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('mcp_action_previews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mcp_access_token_id')->constrained('mcp_access_tokens')->cascadeOnDelete();
            $table->string('action', 64);
            $table->text('payload');
            $table->string('state', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->uuid('idempotency_key')->nullable();
            $table->json('result')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['mcp_access_token_id', 'idempotency_key'], 'mcp_action_token_idempotency_unique');
            $table->index(['user_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_action_previews');
        Schema::dropIfExists('mcp_access_tokens');
    }
};
