<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        Permission::firstOrCreate(['name' => 'view issues']);
        Permission::firstOrCreate(['name' => 'manage issues']);
        Permission::firstOrCreate(['name' => 'view users']);
        Permission::firstOrCreate(['name' => 'manage users']);
        Permission::firstOrCreate(['name' => 'view roles']);
        Permission::firstOrCreate(['name' => 'manage roles']);
        Permission::firstOrCreate(['name' => 'view permissions']);
        Permission::firstOrCreate(['name' => 'manage permissions']);
        Permission::firstOrCreate(['name' => 'access api']);
        Permission::firstOrCreate(['name' => 'view dashboard widgets']);
        Permission::firstOrCreate(['name' => 'view incident types']);
        Permission::firstOrCreate(['name' => 'view labels']);
        Permission::firstOrCreate(['name' => 'view audit logs']);
        Permission::firstOrCreate(['name' => 'access dashboard']);

        // create roles and assign created permissions
        $role = Role::firstOrCreate(['name' => 'user']);
        $role->givePermissionTo(['view incidents', 'view issues', 'view audit logs', 'access dashboard']);

        $role = Role::firstOrCreate(['name' => 'admin']);
        $role->givePermissionTo(Permission::all());
    }
}
