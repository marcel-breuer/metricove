<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Keep fresh installations available for one-time administrator setup.
     */
    public function run(): void
    {
        // The first account must be created by the setup flow so it becomes the administrator.
    }
}
