<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationReview>
 */
class OrganizationReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'action' => 'submitted',
            'note' => null,
            'acted_by' => User::factory(),
        ];
    }
}
