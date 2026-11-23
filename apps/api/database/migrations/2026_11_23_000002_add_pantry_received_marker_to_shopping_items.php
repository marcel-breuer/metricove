<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_items', function (Blueprint $table): void {
            $table->timestampTz('pantry_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_items', function (Blueprint $table): void {
            $table->dropColumn('pantry_received_at');
        });
    }
};
