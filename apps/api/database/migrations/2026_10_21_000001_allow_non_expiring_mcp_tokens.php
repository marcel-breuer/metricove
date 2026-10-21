<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_access_tokens', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('mcp_access_tokens')
            ->whereNull('expires_at')
            ->update(['expires_at' => now()->addYears(100)]);

        Schema::table('mcp_access_tokens', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable(false)->change();
        });
    }
};
