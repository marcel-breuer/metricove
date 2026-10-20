<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('metric_key', 64);
            $table->string('title', 80);
            $table->string('range_key', 8);
            $table->string('chart_type', 8);
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['user_id', 'position', 'id'], 'analytics_widgets_user_order_index');
            $table->index(['user_id', 'metric_key'], 'analytics_widgets_user_metric_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE analytics_widgets ADD CONSTRAINT analytics_widgets_metric_key_check CHECK (metric_key IN ('finance.expenses', 'utilities.consumption', 'vehicles.distance'))");
            DB::statement("ALTER TABLE analytics_widgets ADD CONSTRAINT analytics_widgets_range_key_check CHECK (range_key IN ('30d', '90d', '12m'))");
            DB::statement("ALTER TABLE analytics_widgets ADD CONSTRAINT analytics_widgets_chart_type_check CHECK (chart_type IN ('line', 'bar'))");
            DB::statement('ALTER TABLE analytics_widgets ADD CONSTRAINT analytics_widgets_version_check CHECK (version > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_widgets');
    }
};
