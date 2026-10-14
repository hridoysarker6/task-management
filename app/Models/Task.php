<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Observers\TaskObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int $number
 * @property int|null $parent_id
 * @property string $type
 * @property string $title
 * @property string|null $description
 * @property int $status_id
 * @property int $priority
 * @property int $reporter_id
 * @property int|null $assignee_id
 * @property int|null $sprint_id
 * @property string|null $story_points
 * @property int|null $original_estimate_min
 * @property int|null $remaining_estimate_min
 * @property int $time_spent_min
 * @property Carbon|null $start_date
 * @property Carbon|null $due_date
 * @property string|null $resolution_note
 * @property Carbon|null $completed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'organization_id', 'project_id', 'number', 'parent_id', 'type', 'title', 'description', 'status_id', 'priority',
    'reporter_id', 'assignee_id', 'sprint_id', 'story_points', 'original_estimate_min', 'remaining_estimate_min',
    'start_date', 'due_date', 'board_position', 'backlog_rank', 'resolution_note', 'completed_at', 'created_by', 'updated_by',
])]
#[ObservedBy(TaskObserver::class)]
class Task extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const TYPES = ['epic', 'story', 'task', 'bug', 'subtask'];

    /** Types that may sit directly under an epic. */
    public const UNDER_EPIC = ['story', 'task', 'bug'];

    /** Types a sub-task may hang from. */
    public const SUBTASK_PARENTS = ['story', 'task', 'bug'];

    public const PRIORITIES = [1 => 'Lowest', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Highest'];

    public const LINK_TYPES = ['blocks', 'relates_to', 'duplicates', 'clones'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'priority' => 'integer',
            'number' => 'integer',
        ];
    }

    /** "ECP-42". Needs the project relation (it is loaded on demand). */
    public function getKeyLabelAttribute(): string
    {
        return $this->project->key.'-'.$this->number;
    }

    /** Find a task by its key ("ECP-42") inside the current company, soft-deleted ones included. */
    public static function findByKey(string $key): ?self
    {
        if (! preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d+)$/', $key, $m)) {
            return null;
        }

        $project = Project::where('key', strtoupper($m[1]))->first();

        return $project
            ? self::withTrashed()->where('project_id', $project->id)->where('number', (int) $m[2])->first()
            : null;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /** @return BelongsTo<ProjectStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /** @return HasMany<Task, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<Membership, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'reporter_id')->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Membership, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'assignee_id')->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'label_task');
    }

    /** @return BelongsToMany<Membership, $this> */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'task_watchers')->withoutGlobalScopes()->withTrashed();
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    /** @return HasMany<TaskActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return HasMany<TaskLink, $this> */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(TaskLink::class, 'source_task_id');
    }

    /** @return HasMany<TaskLink, $this> */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(TaskLink::class, 'target_task_id');
    }

    public function isDone(): bool
    {
        return $this->status?->category === 'done';
    }

    /**
     * Tasks the viewer may see (DATABASE.md section 9). A task is visible when the viewer
     *  - reported it, is assigned to it, watches it, or is a current member of its project; or
     *  - falls in the role's data scope: department = work of people in their department, team = work of
     *    themself and everyone below them, organization = everything.
     * External people (hard rule): only member projects, and only tasks they reported, are assigned to or watch,
     * unless the project lets clients see all tasks.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeVisibleTo(Builder $query, Membership $viewer): Builder
    {
        $tasks = $this->getTable();
        $me = $viewer->id;

        $isMember = fn ($sub) => $sub->select(DB::raw(1))->from('project_members')
            ->whereColumn('project_members.project_id', "{$tasks}.project_id")
            ->where('project_members.membership_id', $me)->whereNull('project_members.left_at');
        $watching = fn ($sub) => $sub->select(DB::raw(1))->from('task_watchers')
            ->whereColumn('task_watchers.task_id', "{$tasks}.id")->where('task_watchers.membership_id', $me);

        if ($viewer->isExternal()) {
            return $query->whereExists($isMember)->where(function (Builder $q) use ($tasks, $me, $watching) {
                $q->where("{$tasks}.reporter_id", $me)
                    ->orWhere("{$tasks}.assignee_id", $me)
                    ->orWhereExists($watching)
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('projects')
                        ->whereColumn('projects.id', "{$tasks}.project_id")->where('projects.client_can_see_all', true));
            });
        }

        $scope = $viewer->role->data_scope;

        if ($scope === 'organization') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($tasks, $me, $isMember, $watching, $scope, $viewer) {
            $q->where("{$tasks}.reporter_id", $me)
                ->orWhere("{$tasks}.assignee_id", $me)
                ->orWhereExists($watching)
                ->orWhereExists($isMember);

            if ($scope === 'department' && $viewer->department_id) {
                $people = DB::table('memberships')->where('department_id', $viewer->department_id)->select('id');
                $q->orWhereIn("{$tasks}.assignee_id", $people)->orWhereIn("{$tasks}.reporter_id", $people);
            }

            if ($scope === 'team') {
                $team = DB::table('membership_hierarchy')->where('ancestor_id', $me)->where('depth', '>', 0)->select('descendant_id');
                $q->orWhereIn("{$tasks}.assignee_id", $team)->orWhereIn("{$tasks}.reporter_id", $team);
            }
        });
    }

    /** Remove the company scope for code that must reach a task across companies (never in request code). */
    public function scopeAcrossCompanies(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganizationScope::class);
    }
}
