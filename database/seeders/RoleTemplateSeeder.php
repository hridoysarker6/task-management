<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\DefaultRoleTemplate;
use Illuminate\Database\Seeder;

class RoleTemplateSeeder extends Seeder
{
    /**
     * Creates the default role template (organization_id = null).
     * Roles that already exist are left alone, so edits made by the System Admin survive a re-seed.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $permissionIds = Permission::pluck('id', 'name');
        $matrix = DefaultRoleTemplate::permissions();

        foreach (DefaultRoleTemplate::roles() as $definition) {
            $role = Role::withoutGlobalScopes()->withTrashed()
                ->where('organization_id', null)
                ->where('slug', $definition['slug'])
                ->first();

            if ($role) {
                continue;
            }

            $role = Role::withoutGlobalScopes()->create($definition + ['organization_id' => null]);
            $role->permissions()->sync(
                collect($matrix[$definition['slug']] ?? [])->map(fn ($name) => $permissionIds[$name])->all()
            );
        }
    }
}
