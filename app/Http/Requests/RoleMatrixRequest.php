<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission checks happen in the controllers
    }

    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.id' => ['required', 'integer'],
            'roles.*.data_scope' => ['required', Rule::in(Role::SCOPES)],
            'roles.*.permissions' => ['present', 'array'],
            'roles.*.permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}
