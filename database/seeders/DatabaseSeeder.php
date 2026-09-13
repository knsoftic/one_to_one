<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AdminSeeder::class);

        // Demo users and conversations are only created outside production.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
