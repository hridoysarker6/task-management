<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Editing a person inside a company: role, department, manager, designation, employee code. */
class MemberService
{
    public function __construct(
        private HierarchyService $hierarchy,
        private EmploymentService $employment,
    ) {}

    /**
     * Apply only the keys present in $data. The caller checks the permission for each key
     * (role_id: user.change_role, reports_to_id: user.change_manager, the rest: user.edit).
     *
     * @param  array{role_id?:int,department_id?:?int,reports_to_id?:?int,designation?:?string,employee_code?:?string}  $data
     *
     * @throws ValidationException
     */
    public function update(Membership $member, Membership $actor, array $data): Membership
    {
        $this->assertCanEdit($member, $actor, $data);

        DB::transaction(function () use ($member, $actor, $data) {
            if (array_key_exists('role_id', $data) && (int) $data['role_id'] !== $member->role_id) {
                $this->changeRole($member, $actor, (int) $data['role_id']);
            }

            if (array_key_exists('department_id', $data)) {
                $this->assertDepartment($member, $data['department_id']);
                $member->department_id = $data['department_id'];
            }

            if (array_key_exists('designation', $data)) {
                $member->designation = $data['designation'];
            }

            if (array_key_exists('employee_code', $data)) {
                $this->assertEmployeeCode($member, $data['employee_code'] ?: null);
                $member->employee_code = $data['employee_code'] ?: null;
            }

            $member->save();

            if (array_key_exists('reports_to_id', $data) && $data['reports_to_id'] !== $member->reports_to_id) {
                $manager = $data['reports_to_id']
                    ? Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
                        ->where('organization_id', $member->organization_id)->find($data['reports_to_id'])
                    : null;

                if ($data['reports_to_id'] && ! $manager) {
                    throw ValidationException::withMessages(['reports_to_id' => 'That manager does not exist.']);
                }

                $this->hierarchy->setManager($member, $manager);
            }

            $this->employment->sync($member->refresh());
        });

        return $member->refresh();
    }

    /** @throws ValidationException */
    private function assertCanEdit(Membership $member, Membership $actor, array $data): void
    {
        if ($member->organization_id !== $actor->organization_id) {
            throw ValidationException::withMessages(['member' => 'That person is not in your company.']);
        }

        if ($member->status !== Membership::ACTIVE || $member->deleted_at !== null) {
            throw ValidationException::withMessages(['member' => 'Only active members can be edited.']);
        }

        if ($member->role->level < $actor->role->level) {
            throw ValidationException::withMessages(['member' => 'You cannot edit someone more senior than you.']);
        }

        if ($member->id === $actor->id && (array_key_exists('role_id', $data) || array_key_exists('reports_to_id', $data))) {
            throw ValidationException::withMessages(['member' => 'You cannot change your own role or manager.']);
        }
    }

    /** @throws ValidationException */
    private function changeRole(Membership $member, Membership $actor, int $roleId): void
    {
        $role = Role::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $member->organization_id)->find($roleId);

        if (! $role) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role from this company.']);
        }

        if ($role->level < $actor->role->level) {
            throw ValidationException::withMessages(['role_id' => 'You cannot give someone a role more senior than your own.']);
        }

        if ($role->is_external !== $member->isExternal()) {
            throw ValidationException::withMessages(['role_id' => $member->isExternal()
                ? 'External people can only hold external roles.'
                : 'Internal people cannot be given an external role.']);
        }

        // Do not strip the company of its last admin.
        if ($member->role->is_locked && ! $role->is_locked) {
            $another = Membership::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $member->organization_id)->where('status', Membership::ACTIVE)
                ->where('id', '!=', $member->id)
                ->whereHas('role', fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)->where('is_locked', true))
                ->exists();

            if (! $another) {
                throw ValidationException::withMessages(['role_id' => 'The last Company Admin cannot be moved to another role.']);
            }
        }

        // The new rank must still fit the reporting line: below the manager, above the direct reports.
        if ($member->reports_to_id && $manager = Membership::withoutGlobalScope(OrganizationScope::class)->with('role')->find($member->reports_to_id)) {
            if ($manager->role->level >= $role->level) {
                throw ValidationException::withMessages(['role_id' => "{$role->name} (level {$role->level}) would not report to {$manager->role->name} (level {$manager->role->level}). Change their manager first."]);
            }
        }

        $blocked = Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
            ->where('reports_to_id', $member->id)->where('status', Membership::ACTIVE)->get()
            ->first(fn (Membership $r) => $r->role->level <= $role->level);
        if ($blocked) {
            throw ValidationException::withMessages(['role_id' => "{$role->name} would no longer outrank their direct report {$blocked->user->name}. Move that person first."]);
        }

        $member->role_id = $role->id;
        $member->setRelation('role', $role);
    }

    private function assertDepartment(Membership $member, ?int $departmentId): void
    {
        if ($departmentId !== null && ! Department::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $member->organization_id)->whereKey($departmentId)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'That department does not exist.']);
        }
    }

    private function assertEmployeeCode(Membership $member, ?string $code): void
    {
        if ($code === null) {
            return;
        }

        $taken = Membership::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $member->organization_id)->where('employee_code', $code)
            ->where('id', '!=', $member->id)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['employee_code' => 'That employee code is already in use.']);
        }
    }
}
