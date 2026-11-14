<?php

namespace Tests\Feature;

use App\Domain\Companies\Models\Company;
use App\Domain\Subscriptions\Models\Subscription;
use App\Mail\SubscriptionReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    private function subscribe(Company|User $who, \DateTimeInterface $periodEnd, string $status = Subscription::ACTIVE): Subscription
    {
        return Subscription::create([
            ($who instanceof Company ? 'company_id' : 'user_id') => $who->id,
            'status' => $status, 'gateway' => 'manual', 'price_minor' => 99900, 'gst_minor' => 17982, 'total_minor' => 117882,
            'currency' => 'INR', 'current_period_start' => now()->subDays(27), 'current_period_end' => $periodEnd,
        ]);
    }

    public function test_a_creator_is_warned_once_when_the_month_is_about_to_end(): void
    {
        Mail::fake();
        $creator = User::factory()->create(['email' => 'riya@creators.test']);
        $this->subscribe($creator, now()->addDays(2));

        $this->artisan('subscriptions:remind')->assertSuccessful();
        Mail::assertSent(SubscriptionReminder::class, fn ($m) => $m->hasTo('riya@creators.test') && $m->kind === 'ending' && $m->forCompany === false);

        // Running again the same day sends nothing more.
        $this->artisan('subscriptions:remind')->assertSuccessful();
        Mail::assertSent(SubscriptionReminder::class, 1);
    }

    public function test_nothing_is_sent_while_there_is_plenty_of_time_left(): void
    {
        Mail::fake();
        $this->subscribe(User::factory()->create(), now()->addDays(15));

        $this->artisan('subscriptions:remind')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_only_the_company_admins_hear_about_a_brand_subscription(): void
    {
        Mail::fake();
        $company = $this->createCompany('Acme Foods');
        $admin = $this->createAdmin($company, ['email' => 'owner@acme.test']);
        $this->createMember($company, attributes: ['email' => 'staff@acme.test']);
        $this->subscribe($company, now()->addDays(1));

        $this->artisan('subscriptions:remind')->assertSuccessful();

        Mail::assertSent(SubscriptionReminder::class, 1);
        Mail::assertSent(SubscriptionReminder::class, fn ($m) => $m->hasTo($admin->email) && $m->forCompany === true);
    }

    public function test_an_ended_month_gets_one_e_mail_and_paying_again_starts_the_cycle_over(): void
    {
        Mail::fake();
        $creator = User::factory()->create();
        $subscription = $this->subscribe($creator, now()->subDay(), Subscription::EXPIRED);

        $this->artisan('subscriptions:remind')->assertSuccessful();
        $this->artisan('subscriptions:remind')->assertSuccessful();
        Mail::assertSent(SubscriptionReminder::class, fn ($m) => $m->kind === 'ended');
        Mail::assertSent(SubscriptionReminder::class, 1);

        // They pay: a new period end, and a later reminder fires again.
        $subscription->forceFill(['status' => Subscription::ACTIVE, 'current_period_end' => now()->addDays(2)])->save();
        $this->artisan('subscriptions:remind')->assertSuccessful();
        Mail::assertSent(SubscriptionReminder::class, fn ($m) => $m->kind === 'ending');
        Mail::assertSent(SubscriptionReminder::class, 2);
    }

    public function test_a_long_expired_subscription_is_left_alone_and_dry_run_sends_nothing(): void
    {
        Mail::fake();
        $this->subscribe(User::factory()->create(), now()->subDays(40), Subscription::EXPIRED);
        $this->subscribe(User::factory()->create(), now()->addDay());

        $this->artisan('subscriptions:remind', ['--dry-run' => true])->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame(0, Subscription::whereNotNull('ending_reminded_for')->count());

        $this->artisan('subscriptions:remind')->assertSuccessful();
        Mail::assertSent(SubscriptionReminder::class, 1);
    }
}
