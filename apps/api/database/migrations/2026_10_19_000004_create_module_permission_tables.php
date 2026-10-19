<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->timestampsTz();
            $table->unique(['user_id', 'module_key']);
            $table->index(['module_key', 'user_id']);
        });

        Schema::create('resource_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->morphs('resource');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 16);
            $table->timestampsTz();
            $table->unique(
                ['resource_type', 'resource_id', 'user_id', 'capability'],
                'resource_user_permissions_unique',
            );
            $table->index(['user_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_user_permissions');
        Schema::dropIfExists('module_user_permissions');
    }
};
