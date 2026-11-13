<?php

namespace Tests\Feature;

use App\Domain\Companies\CompanyScope;
use App\Domain\Companies\Models\Invitation;
use App\Domain\Companies\Models\Membership;
use App\Domain\Companies\TenantContext;
use App\Domain\Rbac\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_list_only_contains_the_callers_company(): void
    {
        $a = $this->createCompany('Company A');
        $b = $this->createCompany('Company B');
        $adminA = $this->createAdmin($a);
        $this->createMember($a);
        $memberB = $this->createMember($b);

        Sanctum::actingAs($adminA);

        $emails = collect($this->getJson('/api/v1/team/members')->assertOk()->json('data'))->pluck('email');

        $this->assertCount(2, $emails);
        $this->assertNotContains($memberB->email, $emails);
    }

    public function test_a_company_admin_cannot_modify_or_remove_another_companys_member(): void
    {
        $adminA = $this->createAdmin($this->createCompany('Company A'));
        $memberB = $this->createMember($this->createCompany('Company B'));

        Sanctum::actingAs($adminA);

        $this->putJson("/api/v1/team/members/{$memberB->id}", ['role_id' => Role::findSystem(Role::COMPANY_ADMIN)->id])
            ->assertNotFound();
        $this->deleteJson("/api/v1/team/members/{$memberB->id}")->assertNotFound();

        $this->assertDatabaseHas('company_users', ['user_id' => $memberB->id]);
    }

    public function test_invitations_are_isolated_per_company(): void
    {
        $a = $this->createCompany('Company A');
        $b = $this->createCompany('Company B');
        $adminA = $this->createAdmin($a);

        $foreign = Invitation::create([
            'company_id' => $b->id,
            'email' => 'someone@b.test',
            'role_id' => Role::findSystem(Role::TEAM_MEMBER)->id,
            'token_hash' => Invitation::hashToken('token-b'),
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($adminA);

        $this->getJson('/api/v1/team/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/team/invitations/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('invitations', ['id' => $foreign->id]);
    }

    public function test_tenant_scope_fails_closed_without_a_tenant(): void
    {
        $this->createMember($this->createCompany());
        app(TenantContext::class)->forget();

        $this->assertSame(0, Membership::count());
        $this->assertSame(1, Membership::withoutGlobalScope(CompanyScope::class)->count());
    }

    public function test_tenant_scope_only_returns_rows_of_the_active_tenant(): void
    {
        $a = $this->createCompany('Company A');
        $b = $this->createCompany('Company B');
        $this->createMember($a);
        $this->createMember($b);
        $this->createMember($b);

        app(TenantContext::class)->set($b);

        $this->assertSame(2, Membership::count());
        $this->assertTrue(Membership::all()->every(fn ($m) => $m->company_id === $b->id));
    }

    public function test_suspended_companies_are_locked_out(): void
    {
        $company = $this->createCompany();
        $admin = $this->createAdmin($company);
        $company->update(['status' => 'suspended']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company')->assertForbidden();
    }
}
