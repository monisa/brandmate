<?php

namespace Tests\Feature;

use App\Domain\Subscriptions\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['email' => 'boss@example.com']);
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin;
    }

    public function test_only_a_super_admin_can_use_these_tools(): void
    {
        $company = $this->createCompany();
        $user = $this->createAdmin($company);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/access?search=ab')->assertForbidden();
        $this->patchJson("/api/v1/admin/access/{$user->id}/active", ['active' => false])->assertForbidden();
        $this->postJson("/api/v1/admin/access/{$user->id}/subscription", ['action' => 'grant', 'days' => 5])->assertForbidden();
    }

    public function test_the_super_admin_finds_people_and_sees_their_subscription(): void
    {
        $company = $this->createCompany('Acme');
        $brand = $this->createAdmin($company, ['name' => 'Anita Brand', 'email' => 'anita@acme.test']);

        Sanctum::actingAs($this->superAdmin());
        $this->getJson('/api/v1/admin/access?search=anita')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'anita@acme.test')->assertJsonPath('data.0.kind', 'brand')
            ->assertJsonPath('data.0.company', 'Acme')->assertJsonPath('data.0.subscription.active', false)
            ->assertJsonPath('data.0.deactivated', false);
        $this->getJson('/api/v1/admin/access?search=a')->assertUnprocessable();
        $this->getJson('/api/v1/admin/access?search=boss')->assertOk()->assertJsonCount(0, 'data'); // never lists super admins
        $this->assertNotNull($brand);
    }

    public function test_a_manual_grant_unlocks_the_whole_company_and_a_revoke_removes_it(): void
    {
        $company = $this->createCompany();
        $owner = $this->createAdmin($company);
        $teammate = $this->createMember($company);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/access/{$owner->id}/subscription", ['action' => 'grant', 'days' => 30])->assertOk()
            ->assertJsonPath('data.subscription.active', true)->assertJsonPath('data.subscription.status', 'active');
        $this->assertTrue(Subscription::where('company_id', $company->id)->first()->grantsAccess());
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.granted']);

        $this->postJson("/api/v1/admin/access/{$teammate->id}/subscription", ['action' => 'revoke'])->assertOk()
            ->assertJsonPath('data.subscription.active', false);
        $this->assertFalse(Subscription::where('company_id', $company->id)->first()->grantsAccess());

        $this->postJson("/api/v1/admin/access/{$owner->id}/subscription", ['action' => 'grant'])->assertUnprocessable(); // days are required
    }

    public function test_deactivating_signs_a_person_out_and_blocks_sign_in_until_reactivated(): void
    {
        $user = User::factory()->create(['email' => 'creator@example.com', 'password' => 'Right-Pass-1']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $token = $user->createToken('test')->plainTextToken;
        $this->assertSame(1, $user->tokens()->count());

        Sanctum::actingAs($this->superAdmin());
        $this->patchJson("/api/v1/admin/access/{$user->id}/active", ['active' => false])->assertOk()->assertJsonPath('data.deactivated', true);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotEmpty($token);

        $this->postJson('/api/v1/auth/login', ['email' => 'creator@example.com', 'password' => 'Right-Pass-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->patchJson("/api/v1/admin/access/{$user->id}/active", ['active' => true])->assertOk()->assertJsonPath('data.deactivated', false);
        $this->postJson('/api/v1/auth/login', ['email' => 'creator@example.com', 'password' => 'Right-Pass-1'])->assertOk();
    }

    public function test_the_super_admin_cannot_deactivate_themselves_or_another_super_admin(): void
    {
        $admin = $this->superAdmin();
        $other = User::factory()->create();
        $other->forceFill(['is_super_admin' => true])->save();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/access/{$admin->id}/active", ['active' => false])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/access/{$other->id}/active", ['active' => false])->assertUnprocessable();
        $this->assertNull($admin->fresh()->deactivated_at);
    }
}
