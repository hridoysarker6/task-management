<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Scopes\OrganizationScope;
use App\Support\DefaultWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creating and changing projects, their project manager, members and status. */
class ProjectService
{
    /**
     * @param  array{name:string,key:string,department_id?:?int,manager_membership_id?:?int,start_date?:?string,end_date?:?string,description?:?string}  $data
     *
     * @throws ValidationException
     */
    public function create(Organization $organization, Membership $by, array $data): Project
    {
        $key = strtoupper($data['key']);
        $this->assertKeyFree($organization->id, $key);
        $this->assertDepartment($organization->id, $data['department_id'] ?? null);
        $manager = $this->manager($organization->id, $data['manager_membership_id'] ?? null);

        return DB::transaction(function () use ($organization, $by, $data, $key, $manager) {
            $project = Project::withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $organization->id,
                'key' => $key,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'manager_membership_id' => $manager?->id,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'status' => Project::ACTIVE,
                'created_by' => $by->id,
            ]);

            foreach (DefaultWorkflow::statuses() as $i => $status) {
                $project->statuses()->create($status + ['position' => $i]);
            }

            if ($manager) {
                $this->upsertMember($project, $manager, 'manager', $by);
            }

            // Reload so the returned model carries the column defaults (triage mode, progress mode, ...) too.
            return $project->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, description, department_id, start_date, end_date, key (only before the first task)
     *
     * @throws ValidationException
     */
    public function update(Project $project, array $data): Project
    {
        $this->assertEditable($project);

        if (isset($data['key']) && strtoupper($data['key']) !== $project->key) {
            if ($project->task_counter > 0) {
                throw ValidationException::withMessages(['key' => 'The key cannot change once the project has tasks, because task keys like '.$project->key.'-1 already use it.']);
            }
            $this->assertKeyFree($project->organization_id, strtoupper($data['key']), $project->id);
            $project->key = strtoupper($data['key']);
        }

        if (array_key_exists('department_id', $data)) {
            $this->assertDepartment($project->organization_id, $data['department_id']);
            $project->department_id = $data['department_id'];
        }

        $project->fill(collect($data)->only(['name', 'description', 'start_date', 'end_date'])->all())->save();

        return $project;
    }

    /** Settings: sprints on/off, triage mode, client visibility, self-close, progress mode. */
    public function updateSettings(Project $project, array $data): Project
    {
        $this->assertEditable($project);

        $project->fill(collect($data)->only(['uses_sprints', 'triage_mode', 'client_can_see_all', 'allow_self_close', 'progress_mode'])->all())->save();

        return $project;
    }

    /** @throws ValidationException */
    public function changeStatus(Project $project, string $to): Project
    {
        if (! $project->canMoveTo($to)) {
            throw ValidationException::withMessages(['status' => "A {$project->status} project cannot be set to {$to}."]);
        }

        $project->forceFill(['status' => $to])->save();

        return $project;
    }

    /** Make someone (or nobody) the project manager. The previous manager stays on as an ordinary member. */
    public function changeManager(Project $project, ?int $membershipId, ?Membership $by = null): Project
    {
        $this->assertEditable($project);
        $manager = $this->manager($project->organization_id, $membershipId);

        DB::transaction(function () use ($project, $manager, $by) {
            if ($project->manager_membership_id && $project->manager_membership_id !== $manager?->id) {
                ProjectMember::where('project_id', $project->id)->where('membership_id', $project->manager_membership_id)
                    ->where('project_role', 'manager')->update(['project_role' => 'member']);
            }

            if ($manager) {
                $this->upsertMember($project, $manager, 'manager', $by);
            }

            $project->forceFill(['manager_membership_id' => $manager?->id])->save();
        });

        return $project;
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    // --- members ---------------------------------------------------------------------------------

    /**
     * Add someone to the project. A person who left earlier is brought back (left_at cleared).
     *
     * @throws ValidationException
     */
    public function addMember(Project $project, Membership $member, string $role, ?Membership $by = null): ProjectMember
    {
        $this->assertEditable($project);
        $this->assertCanHold($project, $member, $role);

        if ($role === 'manager') {
            throw ValidationException::withMessages(['project_role' => 'Choose the project manager in the project details.']);
        }

        $existing = ProjectMember::where('project_id', $project->id)->where('membership_id', $member->id)->first();
        if ($existing?->isActive()) {
            throw ValidationException::withMessages(['membership_id' => 'This person is already in the project.']);
        }

        return $this->upsertMember($project, $member, $role, $by);
    }

    /** @throws ValidationException */
    public function changeMemberRole(Project $project, ProjectMember $row, string $role): ProjectMember
    {
        $this->assertEditable($project);

        if ($row->project_id !== $project->id || ! $row->isActive()) {
            throw ValidationException::withMessages(['member' => 'That person is not in the project.']);
        }
        if ($row->project_role === 'manager' || $role === 'manager') {
            throw ValidationException::withMessages(['project_role' => 'Change the project manager in the project details.']);
        }

        $this->assertCanHold($project, Membership::withoutGlobalScopes()->findOrFail($row->membership_id), $role);
        $row->update(['project_role' => $role]);

        return $row;
    }

    /** @throws ValidationException */
    public function removeMember(Project $project, ProjectMember $row): void
    {
        $this->assertEditable($project);

        if ($row->project_id !== $project->id || ! $row->isActive()) {
            throw ValidationException::withMessages(['member' => 'That person is not in the project.']);
        }
        if ($row->project_role === 'manager' || $row->membership_id === $project->manager_membership_id) {
            throw ValidationException::withMessages(['member' => 'Assign another project manager before removing this person.']);
        }

        $row->update(['left_at' => now()]);
    }

    /** Re-add a returning employee to a project they used to work on (rehire). Quietly skips unusable ones. */
    public function restoreMember(Project $project, Membership $member, ?Membership $by = null): bool
    {
        if ($project->isArchived()) {
            return false;
        }

        $previous = ProjectMember::where('project_id', $project->id)->where('membership_id', $member->id)->first();
        $role = $previous && $previous->project_role !== 'manager' ? $previous->project_role : 'member';
        if ($member->isExternal() && ! in_array($role, ['stakeholder', 'viewer'], true)) {
            $role = 'stakeholder';
        }

        $this->upsertMember($project, $member, $role, $by);

        return true;
    }

    // --- rules -----------------------------------------------------------------------------------

    private function upsertMember(Project $project, Membership $member, string $role, ?Membership $by): ProjectMember
    {
        $row = ProjectMember::firstOrNew(['project_id' => $project->id, 'membership_id' => $member->id]);
        $row->fill([
            'project_role' => $role,
            'added_by' => $by?->id,
            'joined_at' => now(),
            'left_at' => null,
        ])->save();

        return $row;
    }

    /** @throws ValidationException */
    private function assertCanHold(Project $project, Membership $member, string $role): void
    {
        if (! in_array($role, ProjectMember::ROLES, true)) {
            throw ValidationException::withMessages(['project_role' => 'Choose a valid project role.']);
        }
        if ($member->organization_id !== $project->organization_id || $member->status !== Membership::ACTIVE || $member->deleted_at !== null) {
            throw ValidationException::withMessages(['membership_id' => 'Choose an active member of this company.']);
        }
        if ($member->isExternal() && ! in_array($role, ['stakeholder', 'viewer'], true)) {
            throw ValidationException::withMessages(['project_role' => 'External people can only be stakeholders or viewers.']);
        }
    }

    /** @throws ValidationException */
    private function assertEditable(Project $project): void
    {
        if ($project->isArchived()) {
            throw ValidationException::withMessages(['project' => 'This project is archived. Restore it to make changes.']);
        }
    }

    /** @throws ValidationException */
    private function assertKeyFree(int $organizationId, string $key, ?int $ignoreId = null): void
    {
        $taken = Project::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organizationId)->where('key', $key)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['key' => "The key {$key} is already used by another project in this company."]);
        }
    }

    /** @throws ValidationException */
    private function assertDepartment(int $organizationId, ?int $departmentId): void
    {
        if ($departmentId !== null && ! Department::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)->whereKey($departmentId)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'That department does not exist.']);
        }
    }

    /** @throws ValidationException */
    private function manager(int $organizationId, ?int $membershipId): ?Membership
    {
        if ($membershipId === null) {
            return null;
        }

        $manager = Membership::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)->where('status', Membership::ACTIVE)->where('user_type', 'internal')
            ->find($membershipId);

        if (! $manager) {
            throw ValidationException::withMessages(['manager_membership_id' => 'The project manager must be an active internal member of this company.']);
        }

        return $manager;
    }
}
