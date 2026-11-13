<?php

namespace Tests\Feature;

use App\Domain\Companies\Models\Company;
use App\Domain\Creators\Models\CreatorPackage;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Requests\Models\CollaborationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(string $email = 'root@example.com'): User
    {
        $admin = User::factory()->create(['email' => $email]);
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin;
    }

    /** A creator who signed up, with one account and one package. */
    private function creator(string $name = 'Riya Sen'): Influencer
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['account_type' => 'creator'])->save();

        $creator = new Influencer(['name' => $name, 'category' => 'Food', 'country' => 'IN', 'status' => Influencer::LISTED]);
        $creator->user()->associate($user);
        $creator->save();

        SocialProfile::create([
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'username' => strtolower(str_replace(' ', '.', $name)),
            'followers' => 1000, 'data_source' => 'self_reported', 'provider' => 'Self-reported',
        ]);
        CreatorPackage::create([
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'title' => '1 Reel', 'price_minor' => 100000,
            'currency' => 'INR', 'delivery_days' => 7, 'is_active' => true,
        ]);

        return $creator;
    }

    public function test_deleting_users_removes_their_data_and_empty_companies_but_never_super_admins_or_yourself(): void
    {
        $admin = $this->superAdmin();
        $otherAdmin = $this->superAdmin('second@example.com');

        $company = $this->createCompany('Acme');
        $owner = $this->createAdmin($company);
        $owner->createToken('device');
        $teammate = $this->createMember($company);
        $keepCompany = $this->createCompany('Stays');
        $keeper = $this->createAdmin($keepCompany);

        $creator = $this->creator();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/users/bulk-delete', [
            'ids' => [$owner->id, $teammate->id, $creator->user_id, $admin->id, $otherAdmin->id],
        ])->assertOk()
            ->assertJsonPath('data.users', 3)
            ->assertJsonPath('data.skipped', 2)   // the caller and the other super admin
            ->assertJsonPath('data.companies', 1); // Acme lost its last member

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('users', ['id' => $teammate->id]);
        $this->assertDatabaseMissing('users', ['id' => $creator->user_id]);
        $this->assertDatabaseMissing('companies', ['name' => 'Acme']);
        $this->assertDatabaseMissing('influencers', ['id' => $creator->id]);
        $this->assertDatabaseCount('social_profiles', 0);
        $this->assertDatabaseCount('creator_packages', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
        $this->assertDatabaseHas('users', ['id' => $keeper->id]);
        $this->assertDatabaseHas('companies', ['name' => 'Stays']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.users_deleted']);
    }

    public function test_a_company_with_other_members_left_is_kept_when_only_one_user_goes(): void
    {
        $company = $this->createCompany('Acme');
        $first = $this->createAdmin($company);
        $this->createMember($company);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/admin/users/bulk-delete', ['ids' => [$first->id]])
            ->assertOk()->assertJsonPath('data.users', 1)->assertJsonPath('data.companies', 0);

        $this->assertDatabaseHas('companies', ['name' => 'Acme']);
    }

    public function test_deleting_companies_removes_their_people_shortlists_and_requests(): void
    {
        $doomed = $this->createCompany('Doomed');
        $member = $this->createAdmin($doomed);
        $safe = $this->createCompany('Safe');
        $safeMember = $this->createAdmin($safe);

        $creator = $this->creator();
        DB::table('shortlists')->insert(['company_id' => $doomed->id, 'name' => 'Launch', 'created_at' => now(), 'updated_at' => now()]);
        CollaborationRequest::create([
            'company_id' => $doomed->id, 'influencer_id' => $creator->id, 'requested_by' => $member->id,
            'title' => 'Hello', 'message' => 'Hi', 'currency' => 'INR', 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/admin/companies/bulk-delete', ['ids' => [$doomed->id, 9999]])
            ->assertOk()
            ->assertJsonPath('data.companies', 1)
            ->assertJsonPath('data.users', 1)
            ->assertJsonPath('data.skipped', 1); // 9999 does not exist

        $this->assertDatabaseMissing('companies', ['id' => $doomed->id]);
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseCount('shortlists', 0);
        $this->assertDatabaseCount('collaboration_requests', 0);
        $this->assertDatabaseHas('companies', ['id' => $safe->id]);
        $this->assertDatabaseHas('users', ['id' => $safeMember->id]);
        $this->assertDatabaseHas('influencers', ['id' => $creator->id]); // creators are unaffected
    }

    public function test_deleting_creators_removes_the_login_and_skips_sample_profiles(): void
    {
        $riya = $this->creator('Riya Sen');
        $ben = $this->creator('Ben Lim');
        $sample = Influencer::create(['name' => 'Sample Person', 'category' => 'Food', 'country' => 'IN']);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/admin/creators/bulk-delete', ['ids' => [$riya->id, $sample->id]])
            ->assertOk()->assertJsonPath('data.creators', 1)->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseMissing('influencers', ['id' => $riya->id]);
        $this->assertDatabaseMissing('users', ['id' => $riya->user_id]);
        $this->assertDatabaseHas('influencers', ['id' => $sample->id]);
        $this->assertDatabaseHas('influencers', ['id' => $ben->id]);
    }

    public function test_only_the_super_admin_can_delete_and_input_is_validated(): void
    {
        $company = $this->createCompany();
        $brandAdmin = $this->createAdmin($company);
        $creator = $this->creator();

        foreach (['users' => $brandAdmin->id, 'companies' => $company->id, 'creators' => $creator->id] as $type => $id) {
            Sanctum::actingAs($brandAdmin);
            $this->postJson("/api/v1/admin/{$type}/bulk-delete", ['ids' => [$id]])->assertForbidden();
            Sanctum::actingAs($creator->user);
            $this->postJson("/api/v1/admin/{$type}/bulk-delete", ['ids' => [$id]])->assertForbidden();
        }
        $this->assertDatabaseHas('companies', ['id' => $company->id]);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/v1/admin/users/bulk-delete', [])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->postJson('/api/v1/admin/users/bulk-delete', ['ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/admin/users/bulk-delete', ['ids' => ['abc']])->assertUnprocessable();
        $this->postJson('/api/v1/admin/users/bulk-delete', ['ids' => range(1, 101)])->assertUnprocessable();
        $this->postJson('/api/v1/admin/users/bulk-delete', ['ids' => [5, 5]])->assertUnprocessable();
    }
}
