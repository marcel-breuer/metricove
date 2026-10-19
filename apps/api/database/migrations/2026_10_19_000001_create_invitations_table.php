<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX invitations_pending_email_normalized_unique
            ON invitations (LOWER(TRIM(email)))
            WHERE accepted_at IS NULL AND revoked_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invitations_pending_email_normalized_unique');
        Schema::dropIfExists('invitations');
    }
};
