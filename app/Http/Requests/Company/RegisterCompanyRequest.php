<?php

namespace App\Http\Requests\Company;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCompanyRequest extends FormRequest
{
    use CompanyDetailsRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => strtolower((string) $this->input('slug')),
            'contact_email' => strtolower(trim((string) $this->input('contact_email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            ...$this->companyRules(),
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_designation' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['required', 'string', 'email', 'max:191', 'unique:'.User::class.',email'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
            ...$this->documentRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'You must accept the platform terms to register.',
            'contact_email.unique' => 'An account with this email already exists. Sign in instead.',
        ];
    }

    /** @return array<string, mixed> */
    public function company(): array
    {
        return $this->safe()->only($this->companyFields());
    }

    /** @return array<string, mixed> */
    public function contact(): array
    {
        return [
            'name' => $this->validated('contact_name'),
            'email' => $this->validated('contact_email'),
            'phone' => $this->validated('contact_phone'),
            'designation' => $this->validated('contact_designation'),
            'password' => $this->validated('password'),
        ];
    }
}
