<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('household_settings', function (Blueprint $table) {
            $table->smallInteger('id')->primary()->default(1);
            $table->timestampTz('setup_completed_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE household_settings ADD CONSTRAINT household_settings_singleton_id_check CHECK (id = 1)',
            );
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER household_settings_singleton_id_insert
                BEFORE INSERT ON household_settings
                WHEN NEW.id != 1
                BEGIN
                    SELECT RAISE(ABORT, 'household_settings id must be 1');
                END
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER household_settings_singleton_id_update
                BEFORE UPDATE OF id ON household_settings
                WHEN NEW.id != 1
                BEGIN
                    SELECT RAISE(ABORT, 'household_settings id must be 1');
                END
                SQL);
        }

        $now = now();

        DB::table('household_settings')->insert([
            'id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $firstUserId = DB::table('users')->orderBy('id')->value('id');

        if ($firstUserId !== null) {
            DB::table('users')->where('id', $firstUserId)->update([
                'is_admin' => true,
                'is_active' => true,
            ]);

            DB::table('household_settings')->where('id', 1)->update([
                'setup_completed_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('household_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'is_active']);
        });
    }
};
