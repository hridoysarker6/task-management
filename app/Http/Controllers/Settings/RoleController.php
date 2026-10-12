<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\BuildsRoleMatrix;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleMatrixRequest;
use App\Http\Requests\RoleRequest;
use App\Models\Role;
use App\Services\RoleManager;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Settings > Roles & Permissions for the current company. */
class RoleController extends Controller
{
    use BuildsRoleMatrix;

    public function __construct(private RoleManager $roles, private CurrentOrganization $current) {}

    public function index(): Response
    {
        Gate::authorize('role.view');

        return Inertia::render('Settings/Roles/Index', $this->matrixProps(Role::all()) + [
            'abilities' => [
                'assign' => Gate::allows('permission.assign'),
                'create' => Gate::allows('role.create'),
                'edit' => Gate::allows('role.edit'),
                'delete' => Gate::allows('role.delete'),
            ],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        Gate::authorize('role.create');

        $copyFrom = $request->filled('copy_from') ? Role::findOrFail($request->integer('copy_from')) : null;

        $this->roles->create($this->current->id(), $request->validated(), $copyFrom);

        return back()->with('success', 'Role created.');
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        Gate::authorize('role.edit');

        $this->roles->update($role, $request->validated());

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('role.delete');

        $this->roles->delete($role);

        return back()->with('success', 'Role deleted.');
    }

    public function updateMatrix(RoleMatrixRequest $request): RedirectResponse
    {
        Gate::authorize('permission.assign');

        $this->roles->syncMatrix($this->current->id(), $request->validated('roles'));

        return back()->with('success', 'Permissions saved. They apply immediately.');
    }
}
