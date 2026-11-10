<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_costs', function (Blueprint $table): void {
            $table->string('invoice_path')->nullable();
            $table->string('invoice_name')->nullable();
            $table->string('invoice_mime', 120)->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_cost_invoice_fields_check CHECK ((invoice_path IS NULL) = (invoice_name IS NULL AND invoice_mime IS NULL))');
            DB::statement("ALTER TABLE vehicle_costs ADD CONSTRAINT vehicle_cost_invoice_mime_check CHECK (invoice_mime IS NULL OR invoice_mime IN ('application/pdf', 'image/jpeg', 'image/png'))");
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_costs', function (Blueprint $table): void {
            $table->dropColumn(['invoice_path', 'invoice_name', 'invoice_mime']);
        });
    }
};
