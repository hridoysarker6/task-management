<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->active(),
            'key' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->words(2, true),
            'status' => Project::ACTIVE,
        ];
    }
}
