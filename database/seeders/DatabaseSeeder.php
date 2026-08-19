<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * This is a single-owner system: the one user account is created via
     * `php artisan app:create-owner-account`, not seeded with a known
     * placeholder password. Intentionally left empty for now — future
     * seeders should only ever seed non-sensitive reference data (e.g.
     * default transaction categories), never user credentials.
     */
    public function run(): void
    {
        //
    }
}
