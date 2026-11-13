<?php

namespace Tests\Feature;

use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeUsersTest extends TestCase
{
    use RefreshDatabase;

    private function sample(string $name): SocialProfile
    {
        $influencer = Influencer::create(['name' => $name, 'category' => 'Food']);

        return SocialProfile::create([
            'influencer_id' => $influencer->id, 'platform' => 'instagram', 'username' => strtolower(str_replace(' ', '.', $name)),
            'followers' => 1000, 'data_source' => 'sample', 'provider' => 'Sample data',
        ]);
    }

    public function test_it_deletes_everyone_but_super_admins_and_their_data(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        $company = $this->createCompany('Acme');
        $owner = $this->createAdmin($company);
        $this->createMember($company);
        $owner->createToken('test');
        $sample = $this->sample('Asha Rao');
        $listId = DB::table('shortlists')->insertGetId(['company_id' => $company->id, 'name' => 'Launch', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shortlist_influencers')->insert(['shortlist_id' => $listId, 'social_profile_id' => $sample->id, 'created_at' => now(), 'updated_at' => now()]);

        $creator = User::factory()->create();
        $profile = new Influencer(['name' => 'Riya Sen', 'status' => Influencer::HIDDEN]);
        $profile->user()->associate($creator);
        $profile->save();

        $this->artisan('users:purge --force')->assertSuccessful();

        $this->assertSame([$admin->id], User::pluck('id')->all());
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_users', 0);
        $this->assertDatabaseCount('shortlists', 0);
        $this->assertDatabaseCount('shortlist_influencers', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('influencers', ['id' => $profile->id]);
        // Sample influencers have no owner and stay.
        $this->assertDatabaseHas('social_profiles', ['id' => $sample->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.users_purged']);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        User::factory()->create()->forceFill(['is_super_admin' => true])->save();
        $this->createAdmin($this->createCompany());

        $this->artisan('users:purge --dry-run')->assertSuccessful();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_it_refuses_when_there_is_no_super_admin(): void
    {
        $this->createAdmin($this->createCompany());

        $this->artisan('users:purge --force')->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
