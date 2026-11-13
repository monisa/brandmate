<?php

namespace Tests\Feature;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Companies\Models\Company;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin;
    }

    /** A creator who signed up, completed their profile and listed it. Approval is whatever the database default gives. */
    private function listedCreator(string $name = 'Riya Sen'): Influencer
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['account_type' => 'creator'])->save();

        $creator = new Influencer(['name' => $name, 'category' => 'Food', 'country' => 'IN', 'status' => Influencer::LISTED]);
        $creator->user()->associate($user);
        $creator->save();

        SocialProfile::create([
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'username' => strtolower(str_replace(' ', '.', $name)),
            'followers' => 12500, 'data_source' => 'self_reported', 'provider' => 'Self-reported',
        ]);

        return $creator;
    }

    private function setApproval(Company|Influencer $subject, string $status): void
    {
        $subject->forceFill(['approval_status' => $status])->save();
    }

    public function test_new_brands_and_creators_start_pending(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Asha', 'email' => 'asha@example.com', 'company_name' => 'Acme',
            'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
        ])->assertCreated()->assertJsonPath('data.user.company.approval_status', 'pending');

        $this->postJson('/api/v1/auth/register-creator', [
            'name' => 'Riya Sen', 'email' => 'riya@example.com',
            'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
        ])->assertCreated();

        $this->assertDatabaseHas('companies', ['name' => 'Acme', 'approval_status' => 'pending']);
        $this->assertDatabaseHas('influencers', ['name' => 'Riya Sen', 'approval_status' => 'pending']);
    }

    public function test_existing_companies_and_creators_are_approved_by_default(): void
    {
        $company = $this->createCompany();
        $creator = $this->listedCreator();

        $this->assertSame('approved', $company->fresh()->approval_status);
        $this->assertSame('approved', $creator->fresh()->approval_status);
    }

    public function test_a_pending_company_cannot_use_brand_features_but_can_still_set_up(): void
    {
        $company = $this->createCompany();
        $this->setApproval($company, ApprovalStatus::PENDING);
        $member = $this->createAdmin($company);

        Sanctum::actingAs($member);
        foreach (['/api/v1/influencers', '/api/v1/influencers/filters', '/api/v1/shortlists', '/api/v1/requests'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('message', 'Your company is waiting for approval by the BrandMate team.');
        }
        $this->postJson('/api/v1/shortlists', ['name' => 'Launch'])->assertForbidden();

        // Setting the company up is still possible while waiting.
        $this->getJson('/api/v1/company')->assertOk();
        $this->getJson('/api/v1/team/members')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.company.approval_status', 'pending');
    }

    public function test_a_rejected_company_is_blocked_and_sees_the_note(): void
    {
        $company = $this->createCompany();
        $company->forceFill(['approval_status' => ApprovalStatus::REJECTED, 'review_note' => 'Please add a website.'])->save();

        Sanctum::actingAs($this->createAdmin($company));
        $this->getJson('/api/v1/influencers')->assertForbidden()
            ->assertJsonPath('message', 'Your company was not approved. See the note on your dashboard.');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.company.review_note', 'Please add a website.');
    }

    public function test_only_approved_and_listed_creators_appear_to_brands(): void
    {
        $creator = $this->listedCreator();
        $profileId = $creator->profiles()->first()->id;
        Sanctum::actingAs($this->createMember($this->createCompany()));

        $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(1, 'data');

        $this->setApproval($creator, ApprovalStatus::PENDING);
        $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/influencers/{$profileId}")->assertNotFound();
        $this->getJson('/api/v1/influencers/filters')->assertOk()->assertJsonPath('data.categories', []);
        $this->getJson("/api/v1/influencers/compare?ids[]={$profileId}")->assertOk()->assertJsonCount(0, 'data');

        $this->setApproval($creator, ApprovalStatus::REJECTED);
        $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(0, 'data');

        $this->setApproval($creator, ApprovalStatus::APPROVED);
        $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/influencers/{$profileId}")->assertOk();
    }

    public function test_the_super_admin_approves_a_company_and_it_can_then_search(): void
    {
        $company = $this->createCompany('Acme');
        $this->setApproval($company, ApprovalStatus::PENDING);
        $member = $this->createAdmin($company);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/companies/{$company->id}/review", ['decision' => 'approved', 'note' => 'Welcome aboard'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.review_note', 'Welcome aboard');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.company_reviewed']);

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/influencers')->assertOk();
    }

    public function test_the_super_admin_approves_and_revokes_a_creator(): void
    {
        $creator = $this->listedCreator();
        $this->setApproval($creator, ApprovalStatus::PENDING);
        $brand = $this->createMember($this->createCompany());

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/creators/{$creator->id}/review", ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('data.approval_status', 'approved')->assertJsonPath('data.email', $creator->user->email);

        Sanctum::actingAs($brand);
        $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/creators/{$creator->id}/review", ['decision' => 'rejected', 'note' => 'Photos missing'])
            ->assertOk()->assertJsonPath('data.approval_status', 'rejected');

        Sanctum::actingAs($brand);
        $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_creator_sees_their_status_and_the_note(): void
    {
        $creator = $this->listedCreator();
        $creator->forceFill(['approval_status' => ApprovalStatus::REJECTED, 'review_note' => 'Add a profile photo.'])->save();

        Sanctum::actingAs($creator->user);
        $this->getJson('/api/v1/creator/profile')->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected')
            ->assertJsonPath('data.review_note', 'Add a profile photo.')
            ->assertJsonPath('data.listed', true);
    }

    public function test_only_the_super_admin_can_review_and_list_for_review(): void
    {
        $company = $this->createCompany();
        $creator = $this->listedCreator();

        foreach ([$this->createAdmin($company), $creator->user] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/admin/companies/{$company->id}/review", ['decision' => 'approved'])->assertForbidden();
            $this->postJson("/api/v1/admin/creators/{$creator->id}/review", ['decision' => 'approved'])->assertForbidden();
            $this->getJson('/api/v1/admin/creators')->assertForbidden();
        }
    }

    public function test_review_input_is_validated(): void
    {
        $company = $this->createCompany();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/admin/companies/{$company->id}/review", ['decision' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson("/api/v1/admin/companies/{$company->id}/review", ['decision' => 'approved', 'note' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/v1/admin/companies/9999/review', ['decision' => 'approved'])->assertNotFound();
    }

    public function test_the_admin_lists_filter_by_approval_and_count_what_is_waiting(): void
    {
        $waitingCompany = $this->createCompany('Waiting Co');
        $this->setApproval($waitingCompany, ApprovalStatus::PENDING);
        $this->createCompany('Live Co');
        $waitingMember = $this->createAdmin($waitingCompany, ['name' => 'Owner Person']);

        $waitingCreator = $this->listedCreator('New Creator');
        $this->setApproval($waitingCreator, ApprovalStatus::PENDING);
        $this->listedCreator('Old Creator');

        // A sample profile has no login, so it never needs review.
        Influencer::create(['name' => 'Sample Person', 'category' => 'Food', 'country' => 'IN']);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/admin/overview')->assertOk()
            ->assertJsonPath('data.pending_companies', 1)
            ->assertJsonPath('data.pending_creators', 1);

        $companies = $this->getJson('/api/v1/admin/companies?approval=pending')->assertOk()->assertJsonCount(1, 'data');
        $companies->assertJsonPath('data.0.name', 'Waiting Co')
            ->assertJsonPath('data.0.approval_status', 'pending')
            ->assertJsonPath('data.0.owner.email', $waitingMember->email);

        $this->getJson('/api/v1/admin/creators?approval=pending')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'New Creator')
            ->assertJsonPath('data.0.accounts.0.platform', 'instagram');
        $this->getJson('/api/v1/admin/creators')->assertOk()->assertJsonCount(2, 'data'); // not the sample profile
        $this->getJson('/api/v1/admin/creators?approval=bogus')->assertUnprocessable();
    }
}
