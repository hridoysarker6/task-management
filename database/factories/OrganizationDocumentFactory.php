<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationDocument>
 */
class OrganizationDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => 'trade_license',
            'path' => 'organization-documents/'.fake()->uuid().'.pdf',
            'original_name' => 'trade-license.pdf',
            'mime' => 'application/pdf',
            'size' => 12345,
            'uploaded_by' => User::factory(),
        ];
    }
}
