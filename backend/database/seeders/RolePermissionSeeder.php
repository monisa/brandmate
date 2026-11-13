<?php

namespace Database\Seeders;

use App\Domain\Rbac\PermissionRegistry;
use Illuminate\Database\Seeder;

/** Safe to run repeatedly; required before anyone can register. */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        PermissionRegistry::sync();
    }
}
