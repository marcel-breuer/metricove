<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('module_user_permissions')->where('module_key', 'ai')->delete();
        DB::table('module_states')->where('module_key', 'ai')->delete();
    }

    public function down(): void
    {
        // The built-in assistant is intentionally not restored as an optional module.
    }
};
