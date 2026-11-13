<?php

namespace Tests;

use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\Membership;
use App\Domain\Rbac\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Roles and permissions are seeded whenever a test refreshes the database. */
    protected $seed = true;

    protected $seeder = RolePermissionSeeder::class;

    protected function createCompany(string $name = 'Acme'): Company
    {
        return Company::create(['name' => $name, 'slug' => Company::uniqueSlug($name)]);
    }

    protected function createMember(Company $company, string $role = Role::TEAM_MEMBER, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        Membership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_id' => Role::findSystem($role)->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    protected function createAdmin(Company $company, array $attributes = []): User
    {
        return $this->createMember($company, Role::COMPANY_ADMIN, $attributes);
    }
}
