<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Role CRUD and the permission matrix, for either one company ($organizationId set)
 * or the System Admin's default template ($organizationId = null).
 */
class RoleManager
{
    public function __construct(private PermissionService $permissions) {}

    /** @param array{name:string,level:int,data_scope:string,description?:?string,is_external?:bool} $data */
    public function create(?int $organizationId, array $data, ?Role $copyFrom = null): Role
    {
        return DB::transaction(function () use ($organizationId, $data, $copyFrom) {
            $role = Role::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($organizationId, $data['name']),
                'level' => $data['level'],
                'data_scope' => $data['data_scope'],
                'is_external' => $data['is_external'] ?? false,
                'is_locked' => false,
                'description' => $data['description'] ?? null,
            ]);

            if ($copyFrom) {
                $role->permissions()->sync($copyFrom->permissions()->pluck('permissions.id')->all());
            }

            app(AuditLogger::class)->record('role.created', $role, null, $role->only(['name', 'level', 'data_scope', 'is_external']), $role->name, platform: $organizationId === null, organizationId: $organizationId);

            return $role;
        });
    }

    /** @param array{name?:string,level?:int,data_scope?:string,description?:?string,is_external?:bool} $data */
    public function update(Role $role, array $data): Role
    {
        $this->assertEditable($role);

        $before = $role->only(['name', 'level', 'data_scope', 'description', 'is_external']);
        $role->fill(collect($data)->only(['name', 'level', 'data_scope', 'description', 'is_external'])->all())->save();

        [$old, $new] = AuditLogger::diff($before, $role->only(array_keys($before)));
        if ($new) {
            app(AuditLogger::class)->record('role.updated', $role, $old, $new, $role->name, platform: $role->organization_id === null);
        }

        return $role;
    }

    public function delete(Role $role): void
    {
        $this->assertEditable($role);

        if (! $role->isTemplate() && Membership::withTrashed()->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages([
                'role' => 'This role is still assigned to people. Move them to another role first.',
            ]);
        }

        app(AuditLogger::class)->record('role.deleted', $role, $role->only(['name', 'level', 'data_scope']), null, $role->name, platform: $role->organization_id === null);

        $role->delete();
    }

    /**
     * Save the matrix. Each entry: ['id' => int, 'data_scope' => string, 'permissions' => list<string>].
     * Only roles belonging to $organizationId are touched; locked roles are skipped.
     *
     * @param  list<array{id:int,data_scope:string,permissions:list<string>}>  $entries
     */
    public function syncMatrix(?int $organizationId, array $entries): void
    {
        $permissionIds = Permission::pluck('id', 'name');

        DB::transaction(function () use ($organizationId, $entries, $permissionIds) {
            $roles = Role::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereIn('id', collect($entries)->pluck('id'))
                ->get()
                ->keyBy('id');

            foreach ($entries as $entry) {
                $role = $roles->get($entry['id']);

                if (! $role || $role->is_locked) {
                    continue;
                }

                $scopeBefore = $role->data_scope;
                $permsBefore = $role->permissions()->pluck('permissions.name')->sort()->values()->all();

                $role->update(['data_scope' => $entry['data_scope']]);
                $role->permissions()->sync(
                    collect($entry['permissions'])->map(fn ($name) => $permissionIds[$name] ?? null)->filter()->unique()->values()->all()
                );

                $this->permissions->forgetRole($role);

                $permsAfter = $role->permissions()->pluck('permissions.name')->sort()->values()->all();
                if ($permsBefore !== $permsAfter || $scopeBefore !== $role->data_scope) {
                    app(AuditLogger::class)->record('role.permissions_updated', $role, ['data_scope' => $scopeBefore, 'removed' => array_values(array_diff($permsBefore, $permsAfter))], ['data_scope' => $role->data_scope, 'added' => array_values(array_diff($permsAfter, $permsBefore))], $role->name, platform: $organizationId === null, organizationId: $organizationId);
                }
            }
        });
    }

    private function assertEditable(Role $role): void
    {
        if ($role->is_locked) {
            throw ValidationException::withMessages(['role' => 'The Company Admin role is locked and cannot be changed.']);
        }
    }

    private function uniqueSlug(?int $organizationId, string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 2;

        while (Role::withoutGlobalScopes()->withTrashed()->where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
