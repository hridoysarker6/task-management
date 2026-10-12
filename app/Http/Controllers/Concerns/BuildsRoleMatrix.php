<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

/** Props for the Roles & Permissions matrix page, shared by the company and System Admin screens. */
trait BuildsRoleMatrix
{
    /** @param Collection<int, Role> $roles */
    protected function matrixProps(Collection $roles): array
    {
        $roles->loadMissing('permissions:id,name')->loadCount('memberships');

        return [
            'roles' => $roles->sortBy('level')->values()->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'level' => $r->level,
                'data_scope' => $r->data_scope,
                'is_external' => $r->is_external,
                'is_locked' => $r->is_locked,
                'description' => $r->description,
                'members_count' => $r->memberships_count,
                'permissions' => $r->permissions->pluck('name')->values(),
            ]),
            'modules' => Permission::orderBy('sort_order')->get()
                ->groupBy('module')
                ->map(fn ($perms, $module) => [
                    'module' => $module,
                    'permissions' => $perms->map->only(['id', 'name', 'label'])->values(),
                ])
                ->values(),
            'scopes' => [
                ['value' => 'own', 'label' => 'Own'],
                ['value' => 'team', 'label' => 'Team'],
                ['value' => 'department', 'label' => 'Department'],
                ['value' => 'organization', 'label' => 'Organization'],
            ],
        ];
    }
}
