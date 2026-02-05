<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // SECURITY WARNING: This creates a default admin user with weak credentials
        // In production, consider:
        // 1. Using environment variables for credentials
        // 2. Creating admin via CLI with secure password
        // 3. Removing this seeder and using php artisan make:filament-user instead
        if (app()->environment('production')) {
            $this->command->warn('Running AdminUserSeeder in production!');
            $this->command->warn('Default admin credentials will be created. Please change the password immediately.');
        }

        // Check if admin already exists to prevent duplicates
        $existingAdmin = User::where('email', 'admin@example.com')->first();
        if ($existingAdmin) {
            $this->command->info('Admin user already exists. Skipping creation.');
            $existingAdmin->assignRole('admin');

            return;
        }

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('admin');

        $this->command->info('Admin user created successfully!');
        $this->command->warn('Email: admin@example.com');
        $this->command->warn('Password: password');
        $this->command->error('Please change the password immediately after first login!');
    }
}
