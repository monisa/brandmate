<?php

namespace Tests\Feature;

use App\Domain\Notifications\WhatsApp;
use App\Domain\Subscriptions\Models\Subscription;
use App\Mail\SubscribeNudge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscribeNudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['subscription.enforced' => true]);
    }

    private function creator(array $attributes = []): User
    {
        return User::factory()->create(['account_type' => 'creator', 'created_at' => now()->subDays(2)] + $attributes);
    }

    public function test_someone_who_signed_up_and_never_paid_is_told_once(): void
    {
        Mail::fake();
        $this->creator(['email' => 'riya@creators.test']);

        $this->artisan('subscriptions:nudge')->assertSuccessful();
        Mail::assertSent(SubscribeNudge::class, fn ($m) => $m->hasTo('riya@creators.test') && $m->forCompany === false);

        $this->artisan('subscriptions:nudge')->assertSuccessful();
        Mail::assertSent(SubscribeNudge::class, 1);
    }

    public function test_a_brand_s_admin_is_told_but_not_the_team_member(): void
    {
        Mail::fake();
        $company = $this->createCompany('Acme Foods');
        $admin = $this->createAdmin($company, ['created_at' => now()->subDays(2)]);
        $this->createMember($company, attributes: ['created_at' => now()->subDays(2)]);

        $this->artisan('subscriptions:nudge')->assertSuccessful();

        Mail::assertSent(SubscribeNudge::class, 1);
        Mail::assertSent(SubscribeNudge::class, fn ($m) => $m->hasTo($admin->email) && $m->forCompany === true);
    }

    public function test_paid_new_old_unverified_and_free_access_accounts_are_left_alone(): void
    {
        Mail::fake();
        $paid = $this->creator();
        Subscription::create([
            'user_id' => $paid->id, 'status' => Subscription::ACTIVE, 'gateway' => 'manual', 'price_minor' => 99900, 'gst_minor' => 17982,
            'total_minor' => 117882, 'currency' => 'INR', 'current_period_start' => now(), 'current_period_end' => now()->addDays(20),
        ]);
        $this->creator(['created_at' => now()->subHours(2)]);           // signed up a moment ago
        $this->creator(['created_at' => now()->subDays(60)]);           // long forgotten
        $this->creator(['email_verified_at' => null]);                   // never confirmed the e-mail

        $this->artisan('subscriptions:nudge')->assertSuccessful();
        Mail::assertNothingSent();

        config(['subscription.enforced' => false]);
        $this->creator();
        $this->artisan('subscriptions:nudge')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_dry_run_sends_nothing_and_marks_nobody(): void
    {
        Mail::fake();
        $this->creator();

        $this->artisan('subscriptions:nudge', ['--dry-run' => true])->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame(0, User::whereNotNull('signup_nudged_at')->count());
    }

    public function test_a_whatsapp_template_goes_to_people_with_a_phone_number(): void
    {
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        config(['services.whatsapp' => ['token' => 'tok', 'phone_id' => '555', 'template' => 'subscribe_nudge', 'language' => 'en']]);
        $this->creator(['name' => 'Riya Sharma', 'phone' => '98765 43210']);
        $this->creator(['phone' => null]);

        $this->artisan('subscriptions:nudge')->assertSuccessful();

        Mail::assertSent(SubscribeNudge::class, 2);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/555/messages')
            && $r['to'] === '919876543210'
            && $r['template']['name'] === 'subscribe_nudge'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Riya');
    }

    public function test_phone_numbers_are_tidied_for_whatsapp(): void
    {
        $this->assertSame('919876543210', WhatsApp::normalise('+91 98765-43210'));
        $this->assertSame('919876543210', WhatsApp::normalise('09876543210'));
        $this->assertNull(WhatsApp::normalise('12345'));
        $this->assertNull(WhatsApp::normalise(null));
    }
}
