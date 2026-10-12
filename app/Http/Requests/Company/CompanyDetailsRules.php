<?php

namespace App\Http\Requests\Company;

use App\Models\Organization;
use Illuminate\Validation\Rule;

/** Validation shared by the registration form and the resubmit form. */
trait CompanyDetailsRules
{
    public const SIZES = ['1-10', '11-50', '51-200', '201-500', '500+'];

    /** @return array<string, mixed> */
    protected function companyRules(?Organization $existing = null): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => [
                'required', 'string', 'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(Organization::RESERVED_SLUGS),
                Rule::unique('organizations', 'slug')->ignore($existing?->id),
            ],
            'industry' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', Rule::in(self::SIZES)],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'website' => ['nullable', 'url', 'max:191'],
            'registration_no' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    protected function documentRules(): array
    {
        $file = ['file', 'mimes:'.implode(',', config('platform.documents.mimes')), 'max:'.config('platform.documents.max_kb')];

        return [
            'trade_license' => ['nullable', ...$file],
            'other_documents' => ['nullable', 'array', 'max:'.config('platform.documents.max_files')],
            'other_documents.*' => $file,
        ];
    }

    /** @return list<string> */
    protected function companyFields(): array
    {
        return ['name', 'slug', 'industry', 'size', 'address', 'phone', 'email', 'website', 'registration_no'];
    }
}
