<?php

namespace Tests\Feature;

use App\Domain\Companies\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAdminTest extends TestCase
{
    use RefreshDatabase;

    private function passwordFile(string $password): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, $password."\n");

        return $file;
    }

    public function test_it_creates_a_verified_company_admin_who_can_sign_in(): void
    {
        $file = $this->passwordFile('Demo-Pass-2026!x');

        $this->artisan('demo:admin Demo@Example.com --password-file='.$file.' --company="Acme Demo"')->assertSuccessful();

        $user = User::where('email', 'demo@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('company_admin', Membership::where('user_id', $user->id)->with('role')->first()->role->slug);
        $this->assertDatabaseHas('companies', ['name' => 'Acme Demo']);

        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.com', 'password' => 'Demo-Pass-2026!x'])
            ->assertOk()->assertJsonPath('data.user.role.slug', 'company_admin');
    }

    public function test_running_it_again_resets_the_password_without_a_second_company(): void
    {
        $this->artisan('demo:admin demo@example.com --password-file='.$this->passwordFile('First-Pass-2026!x'))->assertSuccessful();
        $this->artisan('demo:admin demo@example.com --password-file='.$this->passwordFile('Second-Pass-2026!x'))->assertSuccessful();

        $this->assertDatabaseCount('companies', 1);
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.com', 'password' => 'Second-Pass-2026!x'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.com', 'password' => 'First-Pass-2026!x'])->assertUnprocessable();
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->artisan('demo:admin demo@example.com --password-file='.$this->passwordFile('short'))->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_never_touches_a_super_admin(): void
    {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $hash = $admin->fresh()->password;

        $this->artisan('demo:admin owner@example.com --password-file='.$this->passwordFile('Demo-Pass-2026!x'))->assertFailed();

        $this->assertSame($hash, $admin->fresh()->password);
    }
}
