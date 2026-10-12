<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SystemAdminSeeder extends Seeder
{
    /** Creates the first platform Super Admin from config/platform.php (never overwrites an existing account). */
    public function run(): void
    {
        $config = config('platform.admin');

        $admin = User::firstOrNew(['email' => $config['email']]);

        if ($admin->exists) {
            return;
        }

        $admin->fill(['name' => $config['name'], 'password' => $config['password']]);
        $admin->forceFill(['is_system_admin' => true, 'email_verified_at' => now()])->save();
    }
}
