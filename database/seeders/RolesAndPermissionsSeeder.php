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
        Permission::firstOrCreate(['name' => 'manage api tokens']);
        Permission::firstOrCreate(['name' => 'view ai usage logs']);
        Permission::firstOrCreate(['name' => 'access ai chat']);
        Permission::firstOrCreate(['name' => 'manage service accounts']);
        // Restricted SSO role (owner 2026-11-20): upload proof of completion
        // on action improvements of incidents the user can see.
        Permission::firstOrCreate(['name' => 'submit action proof']);

        // create roles and assign created permissions
        $role = Role::firstOrCreate(['name' => 'user']);
        $role->givePermissionTo(['view incidents', 'view issues', 'view audit logs', 'access dashboard', 'view dashboard widgets', 'view ai usage logs', 'access ai chat']);

        // Restricted SSO role: JIT-assigned on first Microsoft sign-in while
        // role-less. Incidents list scoped to the user's own slice (PIC or
        // responsible-team match — the branch lives in
        // Incident::scopeApplyUserYearAccess), plus proof submission.
        // 'access dashboard' exists ONLY to pass User::canAccessPanel — the
        // Dashboard page stays hidden (no view dashboard widgets) and
        // PanelHome routes team-viewers to the incidents list.
        $role = Role::firstOrCreate(['name' => 'team-viewer']);
        $role->givePermissionTo(['view incidents', 'access dashboard', 'submit action proof']);

        $role = Role::firstOrCreate(['name' => 'admin']);
        $role->givePermissionTo(Permission::all());
    }
}
