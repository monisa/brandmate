<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Fictional influencers so search works before a real data source exists.
        if (config('platform.seed_sample_influencers')) {
            $this->call(SampleInfluencerSeeder::class);
        }

        // Platform owner, created only when SUPER_ADMIN_EMAIL / _PASSWORD are set.
        $admin = config('platform.super_admin');

        if ($admin['email'] && $admin['password']) {
            $user = User::firstOrNew(['email' => strtolower($admin['email'])]);

            if (! $user->exists) {
                $user->fill(['name' => $admin['name'], 'password' => $admin['password']]);
            }

            $user->forceFill(['is_super_admin' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        }
    }
}
