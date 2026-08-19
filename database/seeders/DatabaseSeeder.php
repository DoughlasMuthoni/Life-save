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
     * placeholder password. Seeders here are reference data only (default
     * categories) — never user credentials, and never fabricated financial
     * history (accounts, transactions, balances). A believable-looking fake
     * transaction is exactly the kind of thing that gets mistaken for real
     * data in a finance app.
     */
    public function run(): void
    {
        $this->call(TransactionCategorySeeder::class);
    }
}
