<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ResubmitApplicationRequest extends FormRequest
{
    use CompanyDetailsRules;

    public function authorize(): bool
    {
        return $this->user()?->openApplication()?->isEditableByApplicant() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => strtolower((string) $this->input('slug'))]);
    }

    public function rules(): array
    {
        return [
            ...$this->companyRules($this->user()->openApplication()),
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->documentRules(),
        ];
    }

    /** @return array<string, mixed> */
    public function company(): array
    {
        return $this->safe()->only($this->companyFields());
    }
}
