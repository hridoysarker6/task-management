<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 99999),
            'industry' => 'Software',
            'size' => '11-50',
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'website' => 'https://example.com',
            'registration_no' => fake()->bothify('TL-########'),
            'timezone' => 'Asia/Dhaka',
            'working_days' => [0, 1, 2, 3, 4],
            'status' => Organization::PENDING,
            'applicant_user_id' => User::factory(),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => Organization::ACTIVE, 'approved_at' => now()]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Organization::SUSPENDED, 'suspended_at' => now()]);
    }
}
