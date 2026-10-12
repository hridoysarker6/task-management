<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Collection;

class RoleTemplateService
{
    /**
     * Copy the default role template (roles + permissions) into a company.
     * Roles the company already has (same slug) are left untouched, so this is safe to re-run.
     *
     * @return Collection<string, Role> the company's roles keyed by slug
     */
    public function copyToOrganization(Organization $organization): Collection
    {
        $existing = Role::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy('slug');

        foreach (Role::template()->with('permissions')->orderBy('level')->get() as $template) {
            if ($existing->has($template->slug)) {
                continue;
            }

            $role = Role::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'name' => $template->name,
                'slug' => $template->slug,
                'level' => $template->level,
                'data_scope' => $template->data_scope,
                'is_external' => $template->is_external,
                'is_locked' => $template->is_locked,
                'description' => $template->description,
            ]);
            $role->permissions()->sync($template->permissions->modelKeys());

            $existing->put($role->slug, $role);
        }

        return $existing;
    }
}
