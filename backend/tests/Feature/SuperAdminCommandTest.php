<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private function passwordFile(string $password): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, $password."\n");

        return $file;
    }

    public function test_it_creates_a_verified_super_admin_and_can_reset_the_password(): void
    {
        $this->artisan('admin:super Boss@Example.com --password-file='.$this->passwordFile('First-Pass-1'))->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertTrue($user->is_super_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('First-Pass-1', $user->password));

        $this->artisan('admin:super boss@example.com --password-file='.$this->passwordFile('Second-Pass-2'))->assertSuccessful();
        $this->assertSame(1, User::where('email', 'boss@example.com')->count());
        $this->assertTrue(Hash::check('Second-Pass-2', User::where('email', 'boss@example.com')->value('password')));
    }

    public function test_it_never_converts_a_brand_account_and_rejects_weak_or_missing_passwords(): void
    {
        $brand = User::factory()->create(['email' => 'brand@example.com']);

        $this->artisan('admin:super brand@example.com --password-file='.$this->passwordFile('Good-Pass-1'))->assertFailed();
        $this->assertFalse((bool) $brand->fresh()->is_super_admin);

        $this->artisan('admin:super new@example.com --password-file='.$this->passwordFile('short'))->assertFailed();
        $this->artisan('admin:super new@example.com')->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }
}
