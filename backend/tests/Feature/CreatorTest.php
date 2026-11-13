<?php

namespace Tests\Feature;

use App\Domain\Influencers\Models\Influencer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreatorTest extends TestCase
{
    use RefreshDatabase;

    private function creator(string $name = 'Riya Sen'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['account_type' => 'creator'])->save();

        $profile = new Influencer(['name' => $name, 'status' => Influencer::HIDDEN]);
        $profile->user()->associate($user);
        $profile->save();

        return $user->fresh();
    }

    private function account(array $overrides = []): array
    {
        return $overrides + [
            'platform' => 'instagram', 'username' => 'riya.creates',
            'profile_url' => 'https://www.instagram.com/riya.creates', 'followers' => 12500,
        ];
    }

    public function test_a_creator_can_register_and_starts_hidden(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register-creator', [
            'name' => 'Riya Sen', 'email' => 'riya@example.com',
            'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
        ])->assertCreated()
            ->assertJsonPath('data.user.account_type', 'creator')
            ->assertJsonPath('data.user.company', null)
            ->assertJsonPath('data.user.permissions', []);

        $this->assertDatabaseHas('influencers', ['name' => 'Riya Sen', 'status' => 'hidden']);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_brand_registration_is_still_a_brand_account(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'A', 'email' => 'a@example.com', 'company_name' => 'Acme', 'account_type' => 'creator',
            'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
        ])->assertCreated()->assertJsonPath('data.user.account_type', 'brand');
    }

    public function test_creators_and_brands_cannot_use_each_others_areas(): void
    {
        Sanctum::actingAs($this->creator());
        $this->getJson('/api/v1/creator/profile')->assertOk();
        $this->getJson('/api/v1/influencers')->assertForbidden();
        $this->getJson('/api/v1/shortlists')->assertForbidden();
        $this->getJson('/api/v1/team/members')->assertForbidden();

        Sanctum::actingAs($this->createAdmin($this->createCompany()));
        $this->getJson('/api/v1/creator/profile')->assertForbidden();
        $this->postJson('/api/v1/creator/packages', [])->assertForbidden();
    }

    public function test_listing_needs_a_complete_profile(): void
    {
        Sanctum::actingAs($this->creator());

        $this->putJson('/api/v1/creator/listing', ['listed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('listed');

        $this->putJson('/api/v1/creator/profile', ['name' => 'Riya Sen', 'category' => 'Food', 'country' => 'in', 'city' => 'Kochi'])
            ->assertOk()->assertJsonPath('data.country', 'IN');
        $this->postJson('/api/v1/creator/accounts', $this->account())->assertCreated();

        $this->putJson('/api/v1/creator/listing', ['listed' => true])
            ->assertOk()->assertJsonPath('data.listed', true)->assertJsonPath('data.missing_for_listing', []);
    }

    public function test_listed_creators_appear_in_brand_search_and_hidden_ones_do_not(): void
    {
        $creator = $this->creator();
        Sanctum::actingAs($creator);
        $this->putJson('/api/v1/creator/profile', ['name' => 'Riya Sen', 'category' => 'Food', 'country' => 'IN']);
        $accountId = $this->postJson('/api/v1/creator/accounts', $this->account())->json('data.accounts.0.id');
        $this->postJson('/api/v1/creator/packages', [
            'platform' => 'instagram', 'title' => '1 Reel', 'price' => 15000, 'delivery_days' => 7,
        ])->assertCreated();

        $brand = $this->createMember($this->createCompany());

        Sanctum::actingAs($brand);
        $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/influencers/{$accountId}")->assertNotFound();

        Sanctum::actingAs($creator);
        $this->putJson('/api/v1/creator/listing', ['listed' => true])->assertOk();

        Sanctum::actingAs($brand);
        $this->getJson('/api/v1/influencers')->assertOk()
            ->assertJsonPath('data.0.name', 'Riya Sen')
            ->assertJsonPath('data.0.data_source', 'self_reported')
            ->assertJsonPath('data.0.is_registered_creator', true)
            ->assertJsonPath('data.0.engagement_rate', null);

        $this->getJson("/api/v1/influencers/{$accountId}")->assertOk()
            ->assertJsonPath('data.packages.0.title', '1 Reel')
            ->assertJsonPath('data.packages.0.price', 15000)
            ->assertJsonPath('data.packages.0.currency', 'INR');
    }

    public function test_account_links_must_match_the_platform(): void
    {
        Sanctum::actingAs($this->creator());

        $this->postJson('/api/v1/creator/accounts', $this->account(['profile_url' => 'https://evil.example/riya']))
            ->assertUnprocessable()->assertJsonValidationErrors('profile_url');
        $this->postJson('/api/v1/creator/accounts', $this->account(['profile_url' => 'https://instagram.com.evil.example/riya']))
            ->assertUnprocessable()->assertJsonValidationErrors('profile_url');
        $this->postJson('/api/v1/creator/accounts', $this->account(['username' => '@riya.creates']))
            ->assertCreated()->assertJsonPath('data.accounts.0.username', 'riya.creates')
            ->assertJsonPath('data.accounts.0.verified_data', false);
    }

    public function test_the_same_account_cannot_be_registered_twice(): void
    {
        Sanctum::actingAs($this->creator('Riya Sen'));
        $this->postJson('/api/v1/creator/accounts', $this->account())->assertCreated();

        Sanctum::actingAs($this->creator('Someone Else'));
        $this->postJson('/api/v1/creator/accounts', $this->account())
            ->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_a_creator_cannot_change_another_creators_records(): void
    {
        Sanctum::actingAs($this->creator('Riya Sen'));
        $data = $this->postJson('/api/v1/creator/accounts', $this->account())->json('data');
        $accountId = $data['accounts'][0]['id'];
        $packageId = $this->postJson('/api/v1/creator/packages', [
            'platform' => 'instagram', 'title' => '1 Reel', 'price' => 15000, 'delivery_days' => 7,
        ])->json('data.packages.0.id');

        Sanctum::actingAs($this->creator('Someone Else'));

        $this->putJson("/api/v1/creator/accounts/{$accountId}", $this->account(['followers' => 1]))->assertNotFound();
        $this->deleteJson("/api/v1/creator/accounts/{$accountId}")->assertNotFound();
        $this->putJson("/api/v1/creator/packages/{$packageId}", ['platform' => 'instagram', 'title' => 'x', 'price' => 1, 'delivery_days' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/creator/packages/{$packageId}")->assertNotFound();

        $this->assertDatabaseHas('social_profiles', ['id' => $accountId, 'followers' => 12500]);
        $this->assertDatabaseHas('creator_packages', ['id' => $packageId, 'price_minor' => 1500000]);
    }

    public function test_removing_the_last_account_hides_the_profile(): void
    {
        Sanctum::actingAs($this->creator());
        $this->putJson('/api/v1/creator/profile', ['name' => 'Riya Sen', 'category' => 'Food', 'country' => 'IN']);
        $accountId = $this->postJson('/api/v1/creator/accounts', $this->account())->json('data.accounts.0.id');
        $this->putJson('/api/v1/creator/listing', ['listed' => true])->assertOk();

        $this->deleteJson("/api/v1/creator/accounts/{$accountId}")->assertOk()->assertJsonPath('data.listed', false);
    }

    public function test_inactive_packages_are_not_shown_to_brands(): void
    {
        $creator = $this->creator();
        Sanctum::actingAs($creator);
        $this->putJson('/api/v1/creator/profile', ['name' => 'Riya Sen', 'category' => 'Food', 'country' => 'IN']);
        $accountId = $this->postJson('/api/v1/creator/accounts', $this->account())->json('data.accounts.0.id');
        $this->postJson('/api/v1/creator/packages', ['platform' => 'instagram', 'title' => 'Paused', 'price' => 500, 'delivery_days' => 3, 'is_active' => false])->assertCreated();
        $this->putJson('/api/v1/creator/listing', ['listed' => true])->assertOk();

        Sanctum::actingAs($this->createMember($this->createCompany()));
        $this->getJson("/api/v1/influencers/{$accountId}")->assertOk()->assertJsonCount(0, 'data.packages');
    }
}
