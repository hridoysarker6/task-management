<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Task;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Global search (Ctrl+K): tasks by key or title, projects, and people, each limited to what the person may see. */
class SearchController extends Controller
{
    private const MIN_LENGTH = 2;

    private const PER_KIND = 6;

    public function __construct(private CurrentOrganization $current) {}

    public function __invoke(Request $request): JsonResponse
    {
        $me = $this->current->membership;
        abort_if($me === null, 403);

        $q = trim((string) $request->query('q', ''));
        $empty = ['tasks' => [], 'projects' => [], 'people' => []];

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return response()->json($empty);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        return response()->json([
            'tasks' => Gate::allows('task.view') ? $this->tasks($me, $q, $like) : [],
            'projects' => Gate::allows('project.view') ? $this->projects($me, $like) : [],
            'people' => Gate::allows('user.view') && ! $me->isExternal() ? $this->people($me, $like) : [],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function tasks(Membership $me, string $q, string $like): array
    {
        $query = Task::visibleTo($me)->with(['project', 'status']);

        // "ECP-12" (or the start of it) finds by key; anything else searches titles.
        if (preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d*)$/', $q, $m)) {
            $query->whereHas('project', fn ($p) => $p->where('key', strtoupper($m[1])))
                ->when($m[2] !== '', fn ($t) => $t->where('number', 'like', $m[2].'%'))->orderBy('number');
        } else {
            $query->where(fn ($t) => $t->where('title', 'like', $like))->latest('id');
        }

        return $query->limit(self::PER_KIND)->get()->map(fn (Task $t) => [
            'key' => $t->key_label, 'title' => $t->title, 'status' => $t->status->name, 'url' => route('tasks.show', $t->key_label),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function projects(Membership $me, string $like): array
    {
        return Project::visibleTo($me)->where(fn ($p) => $p->where('name', 'like', $like)->orWhere('key', 'like', $like))
            ->orderBy('name')->limit(self::PER_KIND)->get()
            ->map(fn (Project $p) => ['key' => $p->key, 'name' => $p->name, 'url' => route('projects.show', $p->id)])->all();
    }

    /** @return list<array<string, mixed>> */
    private function people(Membership $me, string $like): array
    {
        return Membership::active()->visibleTo($me)->whereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->with(['user:id,name,email', 'role:id,name'])->limit(self::PER_KIND)->get()
            ->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name, 'url' => route('people.show', $m->id)])->all();
    }
}
