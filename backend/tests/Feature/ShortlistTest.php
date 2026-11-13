<?php

namespace Tests\Feature;

use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Rbac\Models\Permission;
use App\Domain\Rbac\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShortlistTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $name): SocialProfile
    {
        $influencer = Influencer::create(['name' => $name, 'category' => 'Food']);

        return SocialProfile::create([
            'influencer_id' => $influencer->id, 'platform' => 'instagram', 'username' => strtolower(str_replace(' ', '.', $name)),
            'followers' => 1000, 'data_source' => 'sample', 'provider' => 'Sample data',
        ]);
    }

    public function test_full_shortlist_lifecycle(): void
    {
        $a = $this->profile('Asha Rao');
        $b = $this->profile('Ben Lim');
        Sanctum::actingAs($this->createMember($this->createCompany()));

        $id = $this->postJson('/api/v1/shortlists', ['name' => 'Diwali campaign'])
            ->assertCreated()
            ->assertJsonPath('data.influencers_count', 0)
            ->json('data.id');

        $this->postJson("/api/v1/shortlists/{$id}/influencers", ['influencer_ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('data.added', 2);

        // Adding again does not duplicate.
        $this->postJson("/api/v1/shortlists/{$id}/influencers", ['influencer_ids' => [$a->id]])
            ->assertOk()->assertJsonPath('data.added', 0)->assertJsonPath('data.already_in_list', 1);

        $this->putJson("/api/v1/shortlists/{$id}/influencers/{$a->id}", ['notes' => 'Great reels'])->assertOk();
        $this->putJson("/api/v1/shortlists/{$id}", ['name' => 'Festive campaign'])->assertOk()->assertJsonPath('data.name', 'Festive campaign');

        $detail = $this->getJson("/api/v1/shortlists/{$id}")->assertOk()->assertJsonCount(2, 'data.items');
        $notes = collect($detail->json('data.items'))->pluck('notes', 'influencer.name');
        $this->assertSame('Great reels', $notes['Asha Rao']);

        $this->deleteJson("/api/v1/shortlists/{$id}/influencers/{$b->id}")->assertOk();
        $this->getJson('/api/v1/shortlists')->assertOk()->assertJsonPath('data.0.influencers_count', 1);

        $this->deleteJson("/api/v1/shortlists/{$id}")->assertOk();
        $this->assertDatabaseCount('shortlists', 0);
        $this->assertDatabaseCount('shortlist_influencers', 0);
    }

    public function test_names_are_unique_within_a_company_only(): void
    {
        Sanctum::actingAs($this->createMember($this->createCompany('A')));
        $this->postJson('/api/v1/shortlists', ['name' => 'Launch'])->assertCreated();
        $this->postJson('/api/v1/shortlists', ['name' => 'Launch'])->assertUnprocessable()->assertJsonValidationErrors('name');

        Sanctum::actingAs($this->createMember($this->createCompany('B')));
        $this->postJson('/api/v1/shortlists', ['name' => 'Launch'])->assertCreated();
    }

    public function test_shortlists_are_isolated_between_companies(): void
    {
        $profile = $this->profile('Asha Rao');

        Sanctum::actingAs($this->createMember($this->createCompany('A')));
        $id = $this->postJson('/api/v1/shortlists', ['name' => 'Secret plan'])->json('data.id');
        $this->postJson("/api/v1/shortlists/{$id}/influencers", ['influencer_ids' => [$profile->id], 'notes' => 'private'])->assertOk();

        Sanctum::actingAs($this->createMember($this->createCompany('B')));

        $this->getJson('/api/v1/shortlists')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/shortlists/{$id}")->assertNotFound();
        $this->putJson("/api/v1/shortlists/{$id}", ['name' => 'Mine now'])->assertNotFound();
        $this->postJson("/api/v1/shortlists/{$id}/influencers", ['influencer_ids' => [$profile->id]])->assertNotFound();
        $this->putJson("/api/v1/shortlists/{$id}/influencers/{$profile->id}", ['notes' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/shortlists/{$id}/influencers/{$profile->id}")->assertNotFound();
        $this->deleteJson("/api/v1/shortlists/{$id}")->assertNotFound();

        $this->assertDatabaseHas('shortlists', ['id' => $id, 'name' => 'Secret plan']);
        $this->assertDatabaseHas('shortlist_influencers', ['shortlist_id' => $id, 'notes' => 'private']);
    }

    public function test_discovery_and_shortlists_require_their_permissions(): void
    {
        // A role with no permissions at all.
        $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer', 'is_system' => true]);
        $user = $this->createMember($this->createCompany());
        $user->membership->update(['role_id' => $role->id]);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/influencers')->assertForbidden();
        $this->getJson('/api/v1/influencers/1')->assertForbidden();
        $this->getJson('/api/v1/shortlists')->assertForbidden();
        $this->postJson('/api/v1/shortlists', ['name' => 'X'])->assertForbidden();

        // View-only: can read, cannot change.
        $role->permissions()->sync(Permission::whereIn('slug', ['influencer.view', 'influencer.search'])->pluck('id'));
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/influencers')->assertOk();
        $this->getJson('/api/v1/shortlists')->assertOk();
        $this->postJson('/api/v1/shortlists', ['name' => 'X'])->assertForbidden();
    }

    public function test_unknown_influencers_cannot_be_added(): void
    {
        Sanctum::actingAs($this->createMember($this->createCompany()));
        $id = $this->postJson('/api/v1/shortlists', ['name' => 'Launch'])->json('data.id');

        $this->postJson("/api/v1/shortlists/{$id}/influencers", ['influencer_ids' => [999]])
            ->assertUnprocessable();
    }
}
