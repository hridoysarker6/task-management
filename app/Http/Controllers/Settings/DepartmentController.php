<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Membership;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(): Response
    {
        Gate::authorize('department.view');

        return Inertia::render('Settings/Departments/Index', [
            'departments' => Department::with(['head.user:id,name', 'parent:id,name'])
                ->withCount(['memberships' => fn ($q) => $q->where('status', Membership::ACTIVE)])
                ->orderBy('name')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'code' => $d->code,
                    'parent_id' => $d->parent_id,
                    'parent' => $d->parent?->name,
                    'head_membership_id' => $d->head_membership_id,
                    'head' => $d->head?->user?->name,
                    'members_count' => $d->memberships_count,
                ]),
            'people' => Membership::active()->where('user_type', 'internal')->with(['user:id,name', 'role:id,name'])->get()
                ->sortBy(fn ($m) => $m->user->name)
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name])->values(),
            'abilities' => [
                'create' => Gate::allows('department.create'),
                'edit' => Gate::allows('department.edit'),
                'delete' => Gate::allows('department.delete'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('department.create');

        Department::create($this->validated($request));

        return back()->with('success', 'Department created.');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        Gate::authorize('department.edit');

        $department->update($this->validated($request, $department));

        return back()->with('success', 'Department updated.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        Gate::authorize('department.delete');

        if ($department->memberships()->where('status', Membership::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['department' => 'This department still has people in it. Move them to another department first.']);
        }

        if (Department::where('parent_id', $department->id)->exists()) {
            throw ValidationException::withMessages(['department' => 'This department has sub-departments. Remove or move them first.']);
        }

        $department->delete();

        return back()->with('success', 'Department deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Department $department = null): array
    {
        $orgId = $this->current->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150',
                Rule::unique('departments', 'name')->where('organization_id', $orgId)->ignore($department?->id)],
            'code' => ['nullable', 'string', 'max:20'],
            'parent_id' => ['nullable', 'integer',
                Rule::exists('departments', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
                ...($department ? [Rule::notIn([$department->id])] : [])],
            'head_membership_id' => ['nullable', 'integer',
                Rule::exists('memberships', 'id')->where('organization_id', $orgId)->where('status', Membership::ACTIVE)->whereNull('deleted_at')],
        ], ['name.unique' => 'A department with this name already exists.']);

        // A sub-department cannot be placed under one of its own descendants.
        if ($department && ! empty($data['parent_id'])) {
            $cursor = Department::find($data['parent_id']);
            for ($i = 0; $cursor && $i < 50; $i++) {
                if ($cursor->id === $department->id) {
                    throw ValidationException::withMessages(['parent_id' => 'A department cannot sit under its own sub-department.']);
                }
                $cursor = $cursor->parent_id ? Department::find($cursor->parent_id) : null;
            }
        }

        return $data;
    }
}
