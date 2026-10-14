<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->active(),
            'email' => fake()->unique()->safeEmail(),
            'role_id' => Role::factory(),
            'user_type' => 'internal',
            'token_hash' => Invitation::hashToken(Invitation::newToken()),
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ];
    }
}
