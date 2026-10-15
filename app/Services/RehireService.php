<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Notifications\WelcomeBack;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bringing a former employee back. The same membership row is restored, so the old tasks, comments and work logs
 * reconnect automatically. Old permission overrides and project memberships are NOT restored.
 */
class RehireService
{
    public function __construct(
        private HierarchyService $hierarchy,
        private EmploymentService $employment,
        private SeatService $seats,
        private PermissionService $permissions,
        private ProjectService $projects,
    ) {}

    /**
     * Projects the person used to work on and that can still take them back (not archived or deleted).
     *
     * @return Collection<int, Project>
     */
    public function previousProjects(Membership $former): Collection
    {
        $ids = ProjectMember::where('membership_id', $former->id)->pluck('project_id');

        return Project::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $former->organization_id)->whereIn('id', $ids)
            ->where('status', '!=', Project::ARCHIVED)->orderBy('key')->get();
    }

    /** The former (exited, soft-deleted) membership for an email in this company, if any. */
    public function findFormer(Organization $organization, string $email): ?Membership
    {
        return Membership::withoutGlobalScope(OrganizationScope::class)->onlyTrashed()
            ->where('organization_id', $organization->id)
            ->where('status', Membership::EXITED)
            ->whereHas('user', fn ($q) => $q->where('email', strtolower(trim($email))))
            ->first();
    }

    /**
     * Restore the membership with a new role, department and manager (the "same person" path).
     *
     * @param  array{role_id:int,department_id?:?int,reports_to_id?:?int,designation?:?string,employee_code?:?string,joined_at?:?string,project_ids?:list<int>}  $data
     *
     * @throws ValidationException
     */
    public function rehire(Membership $former, Membership $actor, array $data): Membership
    {
        if ($former->deleted_at === null || $former->status !== Membership::EXITED || $former->organization_id !== $actor->organization_id) {
            throw ValidationException::withMessages(['member' => 'Only former employees of this company can be rehired.']);
        }

        $organization = Organization::findOrFail($former->organization_id);
        $this->seats->assertAvailable($organization, 1, 'member');

        $role = Role::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)->find($data['role_id']);
        if (! $role) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role from this company.']);
        }
        if ($role->level < $actor->role->level) {
            throw ValidationException::withMessages(['role_id' => 'You cannot rehire someone into a role more senior than your own.']);
        }

        if (! empty($data['department_id']) && ! Department::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)->whereKey($data['department_id'])->exists()) {
            throw ValidationException::withMessages(['department_id' => 'That department does not exist.']);
        }

        $manager = ! empty($data['reports_to_id'])
            ? Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
                ->where('organization_id', $organization->id)->find($data['reports_to_id'])
            : null;
        if ($manager) {
            $this->hierarchy->assertManagerAllowed($manager, $role, $role->is_external, $organization->id);
        }

        $code = ($data['employee_code'] ?? null) ?: $former->employee_code;
        if ($code && Membership::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organization->id)->where('employee_code', $code)->where('id', '!=', $former->id)->exists()) {
            throw ValidationException::withMessages(['employee_code' => 'That employee code is already in use.']);
        }

        $joinedAt = ! empty($data['joined_at']) ? $data['joined_at'] : now()->toDateString();

        DB::transaction(function () use ($former, $actor, $role, $data, $manager, $code, $joinedAt) {
            $former->restore();
            $former->forceFill([
                'role_id' => $role->id,
                'department_id' => $data['department_id'] ?? null,
                'reports_to_id' => $role->is_external ? null : $manager?->id,
                'user_type' => $role->is_external ? 'external' : 'internal',
                'designation' => $data['designation'] ?? null,
                'employee_code' => $code,
                'status' => Membership::ACTIVE,
                'joined_at' => $joinedAt,
                'scheduled_exit_at' => null,
                'left_at' => null,
                'exit_type' => null,
                'exit_reason' => null,
                'exited_by' => null,
            ])->save();

            // Old per-user exceptions do not come back: only the new role's permissions apply.
            $former->permissionOverrides()->detach();
            $this->permissions->forget($former);

            $this->employment->open($former, $actor);
            $this->hierarchy->rebuildSubtree($former);

            // Project memberships are not restored automatically: only the projects the admin ticked come back.
            foreach ($this->previousProjects($former)->whereIn('id', $data['project_ids'] ?? []) as $project) {
                $this->projects->restoreMember($project, $former, $actor);
            }
        });

        $former->user->notify(new WelcomeBack($organization, $role));

        app(AuditLogger::class)->record('member.rehired', $former, null, ['role_id' => $role->id, 'role' => $role->name], $former->user->name);

        return $former->refresh();
    }

    /**
     * "Different person, same email": free the email by archiving the old account, so the new person can be invited.
     * Refused while the old account is still used anywhere else.
     *
     * @throws ValidationException
     */
    public function archiveOldAccount(Membership $former): void
    {
        $user = $former->user;

        $stillUsed = Membership::withoutGlobalScope(OrganizationScope::class)
            ->where('user_id', $user->id)->exists()
            || $user->applications()->exists();

        if ($stillUsed) {
            throw ValidationException::withMessages([
                'member' => 'This account is still used in another company, so it cannot be archived here. Ask the platform administrator.',
            ]);
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'email' => "archived+{$user->id}@archived.invalid",
                'status' => 'anonymized',
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }

    /** True when this account is safe to archive (no other active memberships or applications). */
    public function canArchive(Membership $former): bool
    {
        $user = User::find($former->user_id);

        return $user !== null
            && ! Membership::withoutGlobalScope(OrganizationScope::class)->where('user_id', $user->id)->exists()
            && ! $user->applications()->exists();
    }
}
