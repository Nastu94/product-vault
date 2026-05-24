<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed principale dell'applicazione.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            LookupSeeder::class,
            TeamTypeSeeder::class,
            PlanSeeder::class,
        ]);
    }
}
