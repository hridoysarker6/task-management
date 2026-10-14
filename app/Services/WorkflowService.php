<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\StatusTransition;
use App\Support\DefaultWorkflow;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A project's workflow: its statuses (board columns) and, optionally, which moves between them are allowed. */
class WorkflowService
{
    /**
     * @param  array{name:string,category:string,color?:?string,show_on_board?:bool,is_initial?:bool}  $data
     *
     * @throws ValidationException
     */
    public function createStatus(Project $project, array $data): ProjectStatus
    {
        $this->assertEditable($project);
        $this->assertNameFree($project, $data['name']);

        return DB::transaction(function () use ($project, $data) {
            $status = $project->statuses()->create([
                'name' => $data['name'],
                'category' => $data['category'],
                'color' => $data['color'] ?? '#6b7280',
                'show_on_board' => $data['show_on_board'] ?? true,
                'is_initial' => false,
                'position' => (int) $project->statuses()->max('position') + 1,
            ]);

            if (! empty($data['is_initial'])) {
                $this->makeInitial($project, $status);
            }

            return $status;
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, category, color, show_on_board, is_initial
     *
     * @throws ValidationException
     */
    public function updateStatus(ProjectStatus $status, array $data): ProjectStatus
    {
        $project = Project::withoutGlobalScopes()->findOrFail($status->project_id);
        $this->assertEditable($project);

        if (isset($data['name'])) {
            $this->assertNameFree($project, $data['name'], $status->id);
        }

        return DB::transaction(function () use ($project, $status, $data) {
            $status->fill(collect($data)->only(['name', 'category', 'color', 'show_on_board'])->all());

            if (array_key_exists('is_initial', $data)) {
                if ($data['is_initial']) {
                    $this->makeInitial($project, $status);
                } elseif ($status->is_initial) {
                    throw ValidationException::withMessages(['is_initial' => 'A project needs one starting status. Make another status the starting one instead.']);
                }
            }

            $status->save();
            $this->assertHasDone($project);

            return $status;
        });
    }

    /**
     * Put the statuses in the given order. The ids must be exactly the project's statuses.
     *
     * @param  list<int>  $orderedIds
     *
     * @throws ValidationException
     */
    public function reorder(Project $project, array $orderedIds): void
    {
        $this->assertEditable($project);

        $existing = $project->statuses()->pluck('id')->all();
        if (count($orderedIds) !== count($existing) || array_diff($existing, $orderedIds) || array_diff($orderedIds, $existing)) {
            throw ValidationException::withMessages(['order' => 'The order must list every status of the project exactly once.']);
        }

        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $position => $id) {
                ProjectStatus::whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /** @throws ValidationException */
    public function deleteStatus(ProjectStatus $status): void
    {
        $project = Project::withoutGlobalScopes()->findOrFail($status->project_id);
        $this->assertEditable($project);

        if ($status->is_initial) {
            throw ValidationException::withMessages(['status' => 'This is the starting status. Make another status the starting one first.']);
        }

        if (($count = $status->taskCount()) > 0) {
            throw ValidationException::withMessages(['status' => "{$count} task(s) are in this status. Move them first."]);
        }

        if ($status->category === 'done' && $project->statuses()->where('category', 'done')->count() === 1) {
            throw ValidationException::withMessages(['status' => 'A project needs at least one status in the Done category.']);
        }

        $status->delete();
    }

    /**
     * Replace the allowed moves. An empty list means every move is allowed.
     *
     * @param  list<array{from_status_id:int,to_status_id:int,required_permission?:?string}>  $moves
     *
     * @throws ValidationException
     */
    public function syncTransitions(Project $project, array $moves): void
    {
        $this->assertEditable($project);

        $ids = $project->statuses()->pluck('id')->all();
        $catalog = PermissionCatalog::names();
        $seen = [];

        foreach ($moves as $move) {
            $key = $move['from_status_id'].'>'.$move['to_status_id'];

            if (! in_array($move['from_status_id'], $ids, true) || ! in_array($move['to_status_id'], $ids, true)) {
                throw ValidationException::withMessages(['moves' => 'Every move must be between statuses of this project.']);
            }
            if ($move['from_status_id'] === $move['to_status_id']) {
                throw ValidationException::withMessages(['moves' => 'A status cannot move to itself.']);
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['moves' => 'The same move is listed twice.']);
            }
            if (! empty($move['required_permission']) && ! in_array($move['required_permission'], $catalog, true)) {
                throw ValidationException::withMessages(['moves' => "Unknown permission {$move['required_permission']}."]);
            }
            $seen[$key] = true;
        }

        DB::transaction(function () use ($project, $moves) {
            StatusTransition::where('project_id', $project->id)->delete();

            foreach ($moves as $move) {
                StatusTransition::create([
                    'project_id' => $project->id,
                    'from_status_id' => $move['from_status_id'],
                    'to_status_id' => $move['to_status_id'],
                    'required_permission' => ($move['required_permission'] ?? null) ?: null,
                ]);
            }
        });
    }

    /**
     * May a task move from one status to another? With no rules set, anything goes.
     * Returns the permission the move needs (string), true (allowed), or false (not allowed).
     */
    public function moveRule(Project $project, int $fromStatusId, int $toStatusId): string|bool
    {
        $rules = StatusTransition::where('project_id', $project->id)->get();

        if ($rules->isEmpty()) {
            return true;
        }

        $rule = $rules->first(fn ($r) => $r->from_status_id === $fromStatusId && $r->to_status_id === $toStatusId);

        return $rule ? ($rule->required_permission ?: true) : false;
    }

    /** Put the default statuses back (used when a project somehow has none). */
    public function seedDefaults(Project $project): void
    {
        foreach (DefaultWorkflow::statuses() as $i => $status) {
            $project->statuses()->create($status + ['position' => $i]);
        }
    }

    // --- rules -----------------------------------------------------------------------------------

    private function makeInitial(Project $project, ProjectStatus $status): void
    {
        ProjectStatus::where('project_id', $project->id)->where('id', '!=', $status->id)->update(['is_initial' => false]);
        $status->is_initial = true;
        $status->save();
    }

    /** @throws ValidationException */
    private function assertNameFree(Project $project, string $name, ?int $ignoreId = null): void
    {
        $taken = $project->statuses()->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'A status with this name already exists.']);
        }
    }

    /** @throws ValidationException */
    private function assertHasDone(Project $project): void
    {
        if (! $project->statuses()->where('category', 'done')->exists()) {
            throw ValidationException::withMessages(['category' => 'A project needs at least one status in the Done category.']);
        }
    }

    /** @throws ValidationException */
    private function assertEditable(Project $project): void
    {
        if ($project->isArchived()) {
            throw ValidationException::withMessages(['project' => 'This project is archived. Restore it to make changes.']);
        }
    }
}
