<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->active(),
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'user_type' => 'internal',
            'status' => Membership::ACTIVE,
            'joined_at' => now()->toDateString(),
        ];
    }
}
