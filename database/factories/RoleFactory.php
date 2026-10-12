<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'organization_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'level' => 70,
            'data_scope' => 'own',
            'is_external' => false,
            'is_locked' => false,
        ];
    }
}
