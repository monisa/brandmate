<?php

namespace App\Domain\Rbac;

use App\Domain\Rbac\Models\Permission;
use App\Domain\Rbac\Models\Role;

/**
 * Single source of truth for permissions and the system roles.
 * Run `php artisan db:seed --class=RolePermissionSeeder` after changing it.
 *
 * Super Admin is not a role: it is the `users.is_super_admin` flag and
 * passes every permission check.
 */
class PermissionRegistry
{
    public const PERMISSIONS = [
        'influencer.view' => 'View influencer profiles',
        'influencer.search' => 'Search influencers',
        'influencer.export' => 'Export influencer data',
        'shortlist.manage' => 'Create and edit shortlists',
        'campaign.view' => 'View campaigns',
        'campaign.create' => 'Create campaigns',
        'campaign.edit' => 'Edit campaigns',
        'campaign.delete' => 'Delete campaigns',
        'analytics.view' => 'View analytics',
        'billing.view' => 'View billing',
        'billing.manage' => 'Manage subscription and billing',
        'team.manage' => 'Invite members and assign roles',
        'company.manage' => 'Edit company settings',
        'audit.view' => 'View the company audit log',
    ];

    public const ROLES = [
        Role::COMPANY_ADMIN => [
            'name' => 'Company Admin',
            'description' => 'Full access to the company workspace',
            'permissions' => '*',
        ],
        Role::TEAM_MEMBER => [
            'name' => 'Team Member',
            'description' => 'Discovers influencers and works on campaigns',
            'permissions' => [
                'influencer.view', 'influencer.search', 'shortlist.manage',
                'campaign.view', 'campaign.create', 'campaign.edit', 'analytics.view',
            ],
        ],
    ];

    public static function sync(): void
    {
        foreach (self::PERMISSIONS as $slug => $description) {
            Permission::updateOrCreate(['slug' => $slug], ['description' => $description]);
        }

        $ids = Permission::pluck('id', 'slug');

        foreach (self::ROLES as $slug => $definition) {
            $role = Role::query()->system()->where('slug', $slug)->first() ?? new Role(['slug' => $slug]);
            $role->fill([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_system' => true,
            ])->save();

            $slugs = $definition['permissions'] === '*' ? array_keys(self::PERMISSIONS) : $definition['permissions'];

            $role->permissions()->sync(collect($slugs)->map(fn ($s) => $ids[$s])->all());
        }
    }
}
