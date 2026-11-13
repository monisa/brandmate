<?php

namespace Tests\Feature;

use App\Domain\Rbac\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_members_cannot_manage_the_team(): void
    {
        $company = $this->createCompany();
        $member = $this->createMember($company);
        $other = $this->createMember($company);
        $roleId = Role::findSystem(Role::COMPANY_ADMIN)->id;

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/team/members')->assertOk(); // viewing is allowed
        $this->postJson('/api/v1/team/invitations', ['email' => 'new@example.com', 'role_id' => $roleId])->assertForbidden();
        $this->putJson("/api/v1/team/members/{$other->id}", ['role_id' => $roleId])->assertForbidden();
        $this->deleteJson("/api/v1/team/members/{$other->id}")->assertForbidden();
        $this->putJson('/api/v1/company', ['name' => 'Renamed', 'timezone' => 'UTC'])->assertForbidden();
    }

    public function test_company_admin_can_change_a_members_role(): void
    {
        $company = $this->createCompany();
        $admin = $this->createAdmin($company);
        $member = $this->createMember($company);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/team/members/{$member->id}", ['role_id' => Role::findSystem(Role::COMPANY_ADMIN)->id])
            ->assertOk()
            ->assertJsonPath('data.role.slug', 'company_admin');

        $this->assertDatabaseHas('audit_logs', ['action' => 'team.role_changed', 'company_id' => $company->id]);
    }

    public function test_the_last_company_admin_cannot_be_demoted(): void
    {
        $admin = $this->createAdmin($this->createCompany());

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/team/members/{$admin->id}", ['role_id' => Role::findSystem(Role::TEAM_MEMBER)->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('member');
    }

    public function test_admin_area_is_for_super_admins_only(): void
    {
        Sanctum::actingAs($this->createAdmin($this->createCompany()));
        $this->getJson('/api/v1/admin/companies')->assertForbidden();

        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();

        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/v1/admin/companies')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_is_super_admin_cannot_be_set_through_registration(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Eve', 'email' => 'eve@example.com', 'company_name' => 'Eve Co',
            'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
            'is_super_admin' => true,
        ])->assertCreated();

        $this->assertFalse(User::firstWhere('email', 'eve@example.com')->isSuperAdmin());
    }
}
