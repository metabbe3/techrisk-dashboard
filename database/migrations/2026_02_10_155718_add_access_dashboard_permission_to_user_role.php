<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'access dashboard' permission to 'user' role.
     * This permission is required for non-admin users to access the Filament panel.
     */
    public function up(): void
    {
        // Create the permission if it doesn't exist
        $permission = Permission::firstOrCreate([
            'name' => 'access dashboard',
            'guard_name' => 'web',
        ]);

        // Assign to user role
        $userRole = Role::where('name', 'user')->where('guard_name', 'web')->first();
        if ($userRole) {
            $userRole->givePermissionTo($permission);
        }

        // Also ensure admin role has this permission (for consistency)
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole && !$adminRole->hasPermissionTo($permission)) {
            $adminRole->givePermissionTo($permission);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'access dashboard' permission from user role
        $permission = Permission::where('name', 'access dashboard')->where('guard_name', 'web')->first();
        if ($permission) {
            $userRole = Role::where('name', 'user')->where('guard_name', 'web')->first();
            if ($userRole) {
                $userRole->revokePermissionTo($permission);
            }
        }
    }
};
