<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_settings', function (Blueprint $table): void {
            $table->string('ai_provider', 32)->nullable();
            $table->string('ai_base_url', 2048)->nullable();
            $table->string('ai_model', 120)->nullable();
            $table->text('ai_api_key')->nullable();
            $table->boolean('ai_external_provider')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('household_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_provider',
                'ai_base_url',
                'ai_model',
                'ai_api_key',
                'ai_external_provider',
            ]);
        });
    }
};
