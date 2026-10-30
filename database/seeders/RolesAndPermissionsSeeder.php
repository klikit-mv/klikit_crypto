<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Idempotent: syncs roles and permissions with config/permissions.php. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $all = [];
        foreach (config('permissions.permissions') as $group => $permissions) {
            foreach ($permissions as $name => $description) {
                $all[$name] = Permission::updateOrCreate(['name' => $name], ['group' => $group, 'description' => $description])->id;
            }
        }
        Permission::whereNotIn('name', array_keys($all))->delete();

        foreach (config('permissions.roles') as $name => $definition) {
            $role = Role::updateOrCreate(['name' => $name], ['label' => $definition['label']]);
            $grants = $definition['permissions'] === ['*'] ? array_values($all) : array_map(fn ($p) => $all[$p], $definition['permissions']);
            $role->permissions()->sync($grants);
        }
    }
}
