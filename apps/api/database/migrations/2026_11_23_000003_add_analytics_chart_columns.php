<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_widgets', function (Blueprint $table): void {
            $table->string('measure_key', 24)->default('change');
            $table->string('group_key', 24)->default('unit');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_widgets', function (Blueprint $table): void {
            $table->dropColumn(['measure_key', 'group_key']);
        });
    }
};
