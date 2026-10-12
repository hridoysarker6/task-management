<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\BuildsRoleMatrix;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleMatrixRequest;
use App\Http\Requests\RoleRequest;
use App\Models\Role;
use App\Services\RoleManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The System Admin's default role template (roles with organization_id = null).
 * Companies get a copy when they are approved; changes here do not touch existing companies.
 */
class RoleTemplateController extends Controller
{
    use BuildsRoleMatrix;

    public function __construct(private RoleManager $roles) {}

    public function index(): Response
    {
        return Inertia::render('Admin/RoleTemplate', $this->matrixProps(Role::template()->get()) + [
            'abilities' => ['assign' => true, 'create' => true, 'edit' => true, 'delete' => true],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $copyFrom = $request->filled('copy_from') ? Role::template()->findOrFail($request->integer('copy_from')) : null;

        $this->roles->create(null, $request->validated(), $copyFrom);

        return back()->with('success', 'Template role created.');
    }

    public function update(RoleRequest $request, int $role): RedirectResponse
    {
        $this->roles->update(Role::template()->findOrFail($role), $request->validated());

        return back()->with('success', 'Template role updated.');
    }

    public function destroy(int $role): RedirectResponse
    {
        $this->roles->delete(Role::template()->findOrFail($role));

        return back()->with('success', 'Template role deleted.');
    }

    public function updateMatrix(RoleMatrixRequest $request): RedirectResponse
    {
        $this->roles->syncMatrix(null, $request->validated('roles'));

        return back()->with('success', 'Default template saved. It applies to companies approved from now on.');
    }
}
