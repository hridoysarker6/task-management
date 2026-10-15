<?php

namespace App\Services;

use App\Events\MembershipExited;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee exit (resign / terminate / contract ended). The user account is never deleted: only the
 * membership ends (soft delete), so that person's history stays attached to them.
 */
class OffboardingService
{
    public const EXIT_TYPES = ['resigned', 'terminated', 'contract_ended'];

    public function __construct(
        private HierarchyService $hierarchy,
        private EmploymentService $employment,
        private ProjectService $projects,
    ) {}

    /**
     * What has to be handed over before this person can leave.
     * Open tasks, projects they manage and timesheet approvals join this list as those modules arrive.
     *
     * @return array{direct_reports: Collection<int, Membership>, projects: Collection<int, Project>}
     */
    public function handoverPlan(Membership $member): array
    {
        return [
            'direct_reports' => Membership::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $member->organization_id)
                ->where('reports_to_id', $member->id)
                ->where('status', Membership::ACTIVE)
                ->with(['user:id,name', 'role:id,name,level'])
                ->get(),
            // Projects they manage need a new project manager (archived ones are read-only and just lose theirs).
            'projects' => Project::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $member->organization_id)
                ->where('manager_membership_id', $member->id)
                ->where('status', '!=', Project::ARCHIVED)
                ->orderBy('key')
                ->get(),
        ];
    }

    /**
     * Start an exit: hand over the direct reports, then exit now (last day today or earlier)
     * or schedule it (last day in the future).
     *
     * @param  array{exit_type:string,left_at:string,exit_reason?:?string,new_manager_id?:?int,new_project_manager_id?:?int}  $data
     *
     * @throws ValidationException
     */
    public function offboard(Membership $member, Membership $actor, array $data): void
    {
        $this->assertCanOffboard($member, $actor);

        $lastDay = Carbon::parse($data['left_at'])->startOfDay();

        if ($member->joined_at && $lastDay->lt($member->joined_at)) {
            throw ValidationException::withMessages(['left_at' => 'The last working day cannot be before the join date.']);
        }

        $plan = $this->handoverPlan($member);
        $reports = $plan['direct_reports'];
        $newManager = $this->resolveNewManager($member, $reports, $data['new_manager_id'] ?? null);
        $newProjectManager = $this->resolveNewProjectManager($member, $plan['projects'], $data['new_project_manager_id'] ?? null);

        DB::transaction(function () use ($member, $actor, $data, $lastDay, $reports, $newManager, $plan, $newProjectManager) {
            foreach ($reports as $report) {
                $this->hierarchy->setManager($report, $newManager);
            }

            foreach ($plan['projects'] as $project) {
                $this->projects->changeManager($project, $newProjectManager->id, $actor);
            }

            $member->forceFill([
                'exit_type' => $data['exit_type'],
                'exit_reason' => $data['exit_reason'] ?? null,
                'exited_by' => $actor->id,
            ]);

            if ($lastDay->lte(today())) {
                $member->save();
                $this->exit($member, $lastDay);
            } else {
                $member->forceFill(['scheduled_exit_at' => $lastDay->toDateString()])->save();
                app(AuditLogger::class)->record('member.exit_scheduled', $member, null, ['exit_type' => $data['exit_type'], 'last_day' => $lastDay->toDateString(), 'reason' => $data['exit_reason'] ?? null], $member->user->name);
            }
        });
    }

    /** Cancel a scheduled exit before its date. */
    public function cancelScheduledExit(Membership $member): void
    {
        $member->forceFill(['scheduled_exit_at' => null, 'exit_type' => null, 'exit_reason' => null, 'exited_by' => null])->save();
        app(AuditLogger::class)->record('member.exit_cancelled', $member, null, null, $member->user->name);
    }

    /**
     * End the membership now. Called by offboard() and by the daily `members:process-exits` command.
     */
    public function exit(Membership $member, ?Carbon $on = null): void
    {
        $on ??= today();

        DB::transaction(function () use ($member, $on) {
            $this->employment->close($member, $on, $member->exit_type, $member->exit_reason);
            app(AuditLogger::class)->record('member.offboarded', $member, null, ['exit_type' => $member->exit_type, 'reason' => $member->exit_reason, 'left_on' => $on->toDateString()], $member->user->name);

            // Anyone still reporting to them (the scheduler path) moves up to their manager.
            $manager = $member->reports_to_id
                ? Membership::withoutGlobalScope(OrganizationScope::class)->with('role')->find($member->reports_to_id)
                : null;
            foreach ($this->handoverPlan($member)['direct_reports'] as $report) {
                try {
                    $this->hierarchy->setManager($report, $manager?->status === Membership::ACTIVE ? $manager : null);
                } catch (ValidationException) {
                    $this->hierarchy->setManager($report, null);
                }
            }

            Department::withoutGlobalScope(OrganizationScope::class)
                ->where('head_membership_id', $member->id)->update(['head_membership_id' => null]);
            DB::table('teams')->where('lead_membership_id', $member->id)->update(['lead_membership_id' => null]);
            DB::table('team_members')->where('membership_id', $member->id)->delete();

            $this->hierarchy->purge($member);

            $member->forceFill([
                'status' => Membership::EXITED,
                'left_at' => $on->toDateString(),
                'scheduled_exit_at' => null,
            ])->save();
            $member->delete(); // soft delete: history stays, restored on rehire

            // Sign them out everywhere; they can sign in again to any other company they belong to.
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $member->user_id)->delete();
            }
        });

        MembershipExited::dispatch($member);
    }

    // --- safety rules ---------------------------------------------------------------------------

    /** @throws ValidationException */
    private function assertCanOffboard(Membership $member, Membership $actor): void
    {
        if ($member->status !== Membership::ACTIVE || $member->deleted_at !== null) {
            throw ValidationException::withMessages(['member' => 'Only active members can be offboarded.']);
        }

        if ($member->organization_id !== $actor->organization_id) {
            throw ValidationException::withMessages(['member' => 'That person is not in your company.']);
        }

        if ($member->id === $actor->id) {
            throw ValidationException::withMessages(['member' => 'You cannot offboard yourself.']);
        }

        if ($member->role->level < $actor->role->level) {
            throw ValidationException::withMessages(['member' => 'You cannot offboard someone more senior than you.']);
        }

        if ($member->role->is_locked) {
            $others = Membership::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $member->organization_id)
                ->where('status', Membership::ACTIVE)
                ->where('id', '!=', $member->id)
                ->whereHas('role', fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)->where('is_locked', true))
                ->exists();

            if (! $others) {
                throw ValidationException::withMessages(['member' => 'The last Company Admin cannot be offboarded. Make someone else Company Admin first.']);
            }
        }
    }

    /**
     * Projects they manage need a new project manager before they leave.
     *
     * @param  Collection<int, Project>  $projects
     *
     * @throws ValidationException
     */
    private function resolveNewProjectManager(Membership $member, Collection $projects, ?int $newManagerId): ?Membership
    {
        if ($projects->isEmpty()) {
            return null;
        }

        if (! $newManagerId) {
            throw ValidationException::withMessages(['new_project_manager_id' => 'Choose who will manage their projects ('.$projects->pluck('key')->implode(', ').').']);
        }

        $manager = Membership::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $member->organization_id)->where('status', Membership::ACTIVE)
            ->where('user_type', 'internal')->where('id', '!=', $member->id)->find($newManagerId);

        if (! $manager) {
            throw ValidationException::withMessages(['new_project_manager_id' => 'Choose another active internal member as the new project manager.']);
        }

        return $manager;
    }

    /**
     * Direct reports must be reassigned before someone leaves.
     *
     * @param  Collection<int, Membership>  $reports
     *
     * @throws ValidationException
     */
    private function resolveNewManager(Membership $member, Collection $reports, ?int $newManagerId): ?Membership
    {
        if ($reports->isEmpty()) {
            return null;
        }

        if (! $newManagerId) {
            throw ValidationException::withMessages(['new_manager_id' => 'Choose who their direct reports will report to.']);
        }

        $newManager = Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
            ->where('organization_id', $member->organization_id)->find($newManagerId);

        if (! $newManager || $newManager->id === $member->id) {
            throw ValidationException::withMessages(['new_manager_id' => 'Choose another active member as the new manager.']);
        }

        // Every report must still be outranked by the new manager, and nobody may end up above themselves.
        foreach ($reports as $report) {
            $this->hierarchy->validateManager($report, $newManager, null, 'new_manager_id');
        }

        return $newManager;
    }
}
