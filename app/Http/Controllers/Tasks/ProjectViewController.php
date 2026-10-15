<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Projects\ProjectBaseController;
use App\Models\Label;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\Sprint;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\TaskFilters;
use Illuminate\Http\Request;

/** Shared parts of the board, list and backlog: the filter bar's choices and the person's saved filters. */
abstract class ProjectViewController extends ProjectBaseController
{
    /** @return array<string, mixed> */
    protected function cleanFilters(Request $request): array
    {
        return TaskFilters::clean($request->query());
    }

    /**
     * What the filter bar can offer in this project. People are left out for external stakeholders (no company directory).
     *
     * @return array<string, mixed>
     */
    protected function filterOptions(Project $project): array
    {
        $me = $this->current->membership;

        $people = $me->isExternal() ? collect() : $project->activeMembers()
            ->with(['membership' => fn ($q) => $q->withoutGlobalScopes()->with('user:id,name')])->get()
            ->map->membership->filter()
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name])->sortBy('name')->values();

        return [
            'statuses' => $project->statuses()->get(['id', 'name', 'color', 'category', 'show_on_board']),
            'people' => $people,
            'labels' => Label::where('project_id', $project->id)->get(['id', 'name', 'color']),
            'sprints' => $project->uses_sprints && ! $me->isExternal()
                ? Sprint::where('project_id', $project->id)->orderByDesc('start_date')->get(['id', 'name', 'status'])
                : [],
            'types' => Task::TYPES,
            'priorities' => collect(Task::PRIORITIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'meId' => $me->id,
        ];
    }

    /**
     * The people the viewer may assign tasks of this project to (inline pickers on the list and board).
     *
     * @return list<array{id:int,name:string,role:string}>
     */
    protected function assigneeChoices(Project $project): array
    {
        return app(TaskService::class)->assigneeOptions($project, $this->current->membership);
    }

    /** @return list<array<string, mixed>> */
    protected function savedFilters(Project $project, string $view): array
    {
        $me = $this->current->membership;

        return SavedFilter::usableBy($me, $project->id)->where('view', $view)->with('owner.user:id,name')->orderBy('name')->get()
            ->map(fn (SavedFilter $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'filters' => $f->filters,
                'is_shared' => $f->is_shared,
                'mine' => $f->membership_id === $me->id,
                'owner' => $f->owner?->user?->name,
            ])->all();
    }
}
