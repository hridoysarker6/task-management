<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Effective permissions of a membership = (role permissions + grants) - revokes,
 * cached per membership and cleared whenever a role, grant or the membership's role changes.
 */
class PermissionService
{
    /** @return list<string> */
    public function forMembership(Membership $membership): array
    {
        return Cache::rememberForever($this->key($membership->id), fn () => $this->resolve($membership));
    }

    public function can(Membership $membership, string $permission): bool
    {
        return in_array($permission, $this->forMembership($membership), true);
    }

    /** Set a per-user exception to the role: 'grant', 'revoke', or null to clear it. */
    public function override(Membership $membership, string $permission, ?string $effect, ?Membership $by = null): void
    {
        $id = Permission::where('name', $permission)->value('id');

        if ($id === null) {
            return;
        }

        $before = DB::table('membership_permission_overrides')->where('membership_id', $membership->id)->where('permission_id', $id)->value('effect');

        if ($effect === null) {
            $membership->permissionOverrides()->detach($id);
        } else {
            $membership->permissionOverrides()->syncWithoutDetaching([$id => ['effect' => $effect, 'granted_by' => $by?->id]]);
        }

        $this->forget($membership);

        if ($before !== $effect) {
            app(AuditLogger::class)->record('permission.override_changed', $membership, ['effect' => $before], ['permission' => $permission, 'effect' => $effect], $permission.' for '.$membership->user->name);
        }
    }

    public function forget(Membership $membership): void
    {
        Cache::forget($this->key($membership->id));
    }

    /** Clear the cache of everyone holding the role (after its permission set changes). */
    public function forgetRole(Role $role): void
    {
        Membership::withoutGlobalScopes()->withTrashed()
            ->where('role_id', $role->id)
            ->pluck('id')
            ->each(fn ($id) => Cache::forget($this->key($id)));
    }

    /** @return list<string> */
    private function resolve(Membership $membership): array
    {
        $role = Role::withoutGlobalScopes()->withTrashed()->find($membership->role_id);

        if (! $role) {
            return [];
        }

        // The locked Company Admin role always holds the whole catalog, including permissions added later.
        $base = $role->is_locked
            ? PermissionCatalog::names()
            : $role->permissions()->pluck('name')->all();

        $overrides = $membership->permissionOverrides()->get();
        $grants = $overrides->where('pivot.effect', 'grant')->pluck('name')->all();
        $revokes = $overrides->where('pivot.effect', 'revoke')->pluck('name')->all();

        return array_values(array_diff(array_unique([...$base, ...$grants]), $revokes));
    }

    private function key(int $membershipId): string
    {
        return "permissions:membership:{$membershipId}";
    }
}
