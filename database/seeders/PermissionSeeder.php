<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /** Idempotent: safe to run again after new permissions are added to the catalog. */
    public function run(): void
    {
        $order = 0;

        foreach (PermissionCatalog::modules() as $module => $permissions) {
            foreach ($permissions as $name => $label) {
                Permission::updateOrCreate(['name' => $name], [
                    'module' => $module,
                    'label' => $label,
                    'sort_order' => $order++,
                ]);
            }
        }
    }
}
