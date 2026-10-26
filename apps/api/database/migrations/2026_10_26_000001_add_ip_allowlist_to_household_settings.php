<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_settings', function (Blueprint $table): void {
            $table->json('ip_allowlist')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('household_settings', function (Blueprint $table): void {
            $table->dropColumn('ip_allowlist');
        });
    }
};
