<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference data and system configuration - SAFE for production
        $this->call([
            IncidentTypeSeeder::class,
            LabelSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);

        // Admin user - USE WITH CAUTION in production
        // Consider creating admin via CLI with secure credentials instead
        $this->call([
            AdminUserSeeder::class,
        ]);

        // Dummy incident data - NEVER run in production!
        // Only run explicitly: php artisan db:seed --class=DummyIncidentSeeder
        // if (app()->environment('local', 'testing')) {
        //     $this->call([DummyIncidentSeeder::class]);
        // }
    }
}
