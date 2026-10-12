<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update a role (company role, or the System Admin's default template when on /admin routes). */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission checks happen in the controllers
    }

    public function rules(): array
    {
        $orgId = $this->routeIs('admin.*') ? null : app(CurrentOrganization::class)->id();

        return [
            'name' => ['required', 'string', 'max:100'],
            'level' => ['required', 'integer', 'min:1', 'max:99'],
            'data_scope' => ['required', Rule::in(Role::SCOPES)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_external' => ['boolean'],
            // Copy the permission set of an existing role (creating only), from the same owner.
            'copy_from' => [
                'nullable',
                Rule::exists('roles', 'id')->where(fn ($q) => ($orgId === null ? $q->whereNull('organization_id') : $q->where('organization_id', $orgId))->whereNull('deleted_at')),
            ],
        ];
    }
}
