<?php

namespace Tests\Feature;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Creators\Models\CreatorPackage;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Rbac\Models\Permission;
use App\Domain\Rbac\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollaborationRequestTest extends TestCase
{
    use RefreshDatabase;

    /** An approved, listed creator with one account. */
    private function creator(string $name = 'Riya Sen'): Influencer
    {
        $user = User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@creators.test']);
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

    private function package(Influencer $creator, bool $active = true): CreatorPackage
    {
        return CreatorPackage::create([
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'title' => '1 Reel', 'price_minor' => 1500000,
            'currency' => 'INR', 'delivery_days' => 7, 'is_active' => $active,
        ]);
    }

    private function payload(Influencer $creator, array $overrides = []): array
    {
        return $overrides + [
            'creator_id' => $creator->id, 'title' => 'Festive reel', 'message' => 'We would love to work with you.',
            'budget' => 20000, 'due_date' => now()->addDays(20)->toDateString(),
        ];
    }

    public function test_a_brand_sends_a_request_and_the_creator_answers_it(): void
    {
        $creator = $this->creator();
        $package = $this->package($creator);
        $brand = $this->createMember($this->createCompany('Acme Foods'), attributes: ['name' => 'Priya', 'email' => 'priya@acme.test']);

        Sanctum::actingAs($brand);
        $id = $this->postJson('/api/v1/requests', $this->payload($creator, ['package_id' => $package->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.budget', 20000)
            ->assertJsonPath('data.package.title', '1 Reel')
            ->assertJsonPath('data.creator.name', 'Riya Sen')
            ->assertJsonPath('data.creator.email', null) // contact details wait for the creator to accept
            ->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.sent']);
        $this->assertDatabaseHas('collaboration_requests', ['id' => $id, 'budget_minor' => 2000000, 'currency' => 'INR']);

        // The creator sees it, with the brand, but not the brand's e-mail yet.
        Sanctum::actingAs($creator->user);
        $this->getJson('/api/v1/creator/requests')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('data.0.title', 'Festive reel')
            ->assertJsonPath('data.0.brand.company', 'Acme Foods')
            ->assertJsonPath('data.0.brand.requested_by', 'Priya')
            ->assertJsonPath('data.0.brand.email', null);
        $this->getJson('/api/v1/creator/profile')->assertJsonPath('data.pending_requests', 1);

        $this->patchJson("/api/v1/creator/requests/{$id}", ['decision' => 'accepted', 'response' => 'Happy to help!'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.creator_response', 'Happy to help!')
            ->assertJsonPath('data.brand.email', 'priya@acme.test'); // shared once accepted
        $this->getJson('/api/v1/creator/requests')->assertJsonPath('pending_count', 0);

        // The brand sees the answer and the creator's contact.
        Sanctum::actingAs($brand);
        $this->getJson('/api/v1/requests')->assertOk()
            ->assertJsonPath('data.0.status', 'accepted')
            ->assertJsonPath('data.0.creator_response', 'Happy to help!')
            ->assertJsonPath('data.0.creator.email', 'riya.sen@creators.test');
    }

    public function test_a_creator_can_decline_and_cannot_answer_twice(): void
    {
        $creator = $this->creator();
        Sanctum::actingAs($this->createMember($this->createCompany()));
        $id = $this->postJson('/api/v1/requests', $this->payload($creator))->assertCreated()->json('data.id');

        Sanctum::actingAs($creator->user);
        $this->patchJson("/api/v1/creator/requests/{$id}", ['decision' => 'declined', 'response' => 'Fully booked.'])
            ->assertOk()->assertJsonPath('data.status', 'declined')->assertJsonPath('data.brand.email', null);
        $this->patchJson("/api/v1/creator/requests/{$id}", ['decision' => 'accepted'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->patchJson("/api/v1/creator/requests/{$id}", ['decision' => 'maybe'])->assertUnprocessable();
    }

    public function test_requests_can_only_go_to_creators_brands_can_see(): void
    {
        $hidden = $this->creator('Hidden One');
        $hidden->forceFill(['status' => Influencer::HIDDEN])->save();
        $pending = $this->creator('Pending One');
        $pending->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();

        Sanctum::actingAs($this->createMember($this->createCompany()));
        $this->postJson('/api/v1/requests', $this->payload($hidden))->assertNotFound();
        $this->postJson('/api/v1/requests', $this->payload($pending))->assertNotFound();
        $this->assertDatabaseCount('collaboration_requests', 0);
    }

    public function test_a_pending_company_cannot_send_requests(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany();
        $company->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();

        Sanctum::actingAs($this->createAdmin($company));
        $this->postJson('/api/v1/requests', $this->payload($creator))->assertForbidden();
        $this->assertDatabaseCount('collaboration_requests', 0);
    }

    public function test_input_is_validated(): void
    {
        $creator = $this->creator();
        $other = $this->creator('Other Creator');
        $otherPackage = $this->package($other);
        $inactive = $this->package($creator, active: false);

        Sanctum::actingAs($this->createMember($this->createCompany()));

        $this->postJson('/api/v1/requests', ['creator_id' => $creator->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'message']);
        $this->postJson('/api/v1/requests', $this->payload($creator, ['due_date' => now()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->postJson('/api/v1/requests', $this->payload($creator, ['budget' => -5]))
            ->assertUnprocessable()->assertJsonValidationErrors('budget');
        $this->postJson('/api/v1/requests', $this->payload($creator, ['message' => str_repeat('x', 2001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('message');
        // Another creator's package, or one that is switched off, cannot be attached.
        $this->postJson('/api/v1/requests', $this->payload($creator, ['package_id' => $otherPackage->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $this->postJson('/api/v1/requests', $this->payload($creator, ['package_id' => $inactive->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('package_id');

        // Budget and due date are optional.
        $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => 'Hello', 'message' => 'Hi there'])
            ->assertCreated()->assertJsonPath('data.budget', null)->assertJsonPath('data.due_date', null);
    }

    public function test_a_brand_can_withdraw_only_a_request_nobody_has_answered(): void
    {
        $creator = $this->creator();
        $brand = $this->createMember($this->createCompany());
        Sanctum::actingAs($brand);

        $first = $this->postJson('/api/v1/requests', $this->payload($creator))->json('data.id');
        $second = $this->postJson('/api/v1/requests', $this->payload($creator, ['title' => 'Second']))->json('data.id');

        $this->deleteJson("/api/v1/requests/{$first}")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->deleteJson("/api/v1/requests/{$first}")->assertUnprocessable();

        Sanctum::actingAs($creator->user);
        $this->patchJson("/api/v1/creator/requests/{$first}", ['decision' => 'accepted'])->assertUnprocessable(); // withdrawn
        $this->patchJson("/api/v1/creator/requests/{$second}", ['decision' => 'accepted'])->assertOk();

        Sanctum::actingAs($brand);
        $this->deleteJson("/api/v1/requests/{$second}")->assertUnprocessable(); // already answered
    }

    public function test_companies_and_creators_only_see_their_own_requests(): void
    {
        $riya = $this->creator('Riya Sen');
        $ben = $this->creator('Ben Lim');
        $acme = $this->createMember($this->createCompany('Acme'));
        $globex = $this->createMember($this->createCompany('Globex'));

        Sanctum::actingAs($acme);
        $acmeRequest = $this->postJson('/api/v1/requests', $this->payload($riya))->json('data.id');

        Sanctum::actingAs($globex);
        $this->getJson('/api/v1/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/requests/{$acmeRequest}")->assertNotFound();

        Sanctum::actingAs($ben->user);
        $this->getJson('/api/v1/creator/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/creator/requests/{$acmeRequest}", ['decision' => 'accepted'])->assertNotFound();

        Sanctum::actingAs($riya->user);
        $this->getJson('/api/v1/creator/requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_status_filter_and_areas_are_separate(): void
    {
        $creator = $this->creator();
        $brand = $this->createMember($this->createCompany());

        Sanctum::actingAs($brand);
        $this->postJson('/api/v1/requests', $this->payload($creator))->assertCreated();
        $this->getJson('/api/v1/requests?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/requests?status=accepted')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/requests?status=bogus')->assertUnprocessable();
        $this->getJson('/api/v1/creator/requests')->assertForbidden(); // brands do not have an inbox

        Sanctum::actingAs($creator->user);
        $this->getJson('/api/v1/requests')->assertForbidden();
        $this->postJson('/api/v1/requests', $this->payload($creator))->assertForbidden();
    }

    public function test_team_members_and_admins_can_send_requests_but_a_role_without_the_permission_cannot(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany();

        foreach ([Role::TEAM_MEMBER, Role::COMPANY_ADMIN] as $role) {
            Sanctum::actingAs($this->createMember($company, $role));
            $this->postJson('/api/v1/requests', $this->payload($creator))->assertCreated();
        }

        // Take the permission away from the role and the endpoints close for its members.
        Role::findSystem(Role::TEAM_MEMBER)->permissions()->detach(Permission::where('slug', 'request.manage')->value('id'));
        Sanctum::actingAs($this->createMember($company));
        $this->getJson('/api/v1/requests')->assertForbidden();
        $this->postJson('/api/v1/requests', $this->payload($creator))->assertForbidden();
    }
}
