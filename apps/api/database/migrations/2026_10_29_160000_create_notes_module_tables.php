<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['owner_user_id', 'name']);
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('note_folders')->nullOnDelete();
            $table->string('title', 180);
            $table->longText('body');
            $table->boolean('shared_with_household')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['owner_user_id', 'archived_at', 'updated_at']);
            $table->index(['shared_with_household', 'archived_at', 'updated_at']);
        });

        Schema::create('note_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 48);
            $table->string('normalized_name', 48);
            $table->timestampsTz();
            $table->unique(['owner_user_id', 'normalized_name']);
        });

        Schema::create('note_note_tag', function (Blueprint $table): void {
            $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
            $table->foreignId('note_tag_id')->constrained('note_tags')->cascadeOnDelete();
            $table->primary(['note_id', 'note_tag_id']);
            $table->index('note_tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_note_tag');
        Schema::dropIfExists('note_tags');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('note_folders');
    }
};
