<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $department_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int|null $manager_membership_id
 * @property string $status
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property bool $uses_sprints
 * @property string $progress_mode
 * @property string $triage_mode
 * @property bool $client_can_see_all
 * @property bool $allow_self_close
 * @property int $task_counter
 * @property int|null $created_by
 */
#[Fillable([
    'organization_id', 'department_id', 'key', 'name', 'description', 'manager_membership_id', 'status',
    'start_date', 'end_date', 'uses_sprints', 'progress_mode', 'triage_mode', 'client_can_see_all',
    'allow_self_close', 'created_by',
])]
class Project extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const COMPLETED = 'completed';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::ACTIVE, self::ON_HOLD, self::COMPLETED, self::ARCHIVED];

    /** Which status a project may move to from each status. */
    public const MOVES = [
        self::ACTIVE => [self::ON_HOLD, self::COMPLETED, self::ARCHIVED],
        self::ON_HOLD => [self::ACTIVE, self::ARCHIVED],
        self::COMPLETED => [self::ACTIVE, self::ARCHIVED],
        self::ARCHIVED => [self::ACTIVE],
    ];

    public const TRIAGE_MODES = ['off', 'external_only', 'all_stakeholders'];

    public const PROGRESS_MODES = ['count', 'points'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'uses_sprints' => 'boolean',
            'client_can_see_all' => 'boolean',
            'allow_self_close' => 'boolean',
        ];
    }

    /** @return BelongsTo<Membership, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'manager_membership_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<ProjectMember, $this> */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class)->whereNull('left_at');
    }

    /** @return HasMany<ProjectStatus, $this> */
    public function statuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<StatusTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(StatusTransition::class);
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('name');
    }

    public function isArchived(): bool
    {
        return $this->status === self::ARCHIVED;
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::MOVES[$this->status] ?? [], true);
    }

    /**
     * Projects the viewer may see. Project membership always counts; on top of that the role's data scope
     * decides (organization = all, department = their department's, team = projects their team works on,
     * own = only their own). External people see member projects only (hard rule, not configurable).
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisibleTo(Builder $query, Membership $viewer): Builder
    {
        $scope = $viewer->role->data_scope;

        if (! $viewer->isExternal() && $scope === 'organization') {
            return $query;
        }

        $projects = $this->qualifyColumn('id');

        return $query->where(function (Builder $q) use ($viewer, $scope, $projects) {
            // Their own projects: a current member of it.
            $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('project_members')
                ->whereColumn('project_members.project_id', $projects)
                ->where('project_members.membership_id', $viewer->id)
                ->whereNull('project_members.left_at'));

            if ($viewer->isExternal()) {
                return;
            }

            $q->orWhere($this->qualifyColumn('manager_membership_id'), $viewer->id)
                ->orWhere($this->qualifyColumn('created_by'), $viewer->id);

            if ($scope === 'department' && $viewer->department_id) {
                $q->orWhere($this->qualifyColumn('department_id'), $viewer->department_id);
            }

            if ($scope === 'team') {
                $team = DB::table('membership_hierarchy')->where('ancestor_id', $viewer->id)->select('descendant_id');

                $q->orWhereIn($this->qualifyColumn('manager_membership_id'), $team)
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('project_members')
                        ->whereColumn('project_members.project_id', $projects)
                        ->whereNull('project_members.left_at')
                        ->whereIn('project_members.membership_id', DB::table('membership_hierarchy')
                            ->where('ancestor_id', $viewer->id)->select('descendant_id')));
            }
        });
    }
}
