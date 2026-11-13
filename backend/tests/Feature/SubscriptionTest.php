<?php

namespace Tests\Feature;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Companies\Models\Company;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Subscriptions\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'rzp_test_secret';

    private const WEBHOOK_SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'subscription.enforced' => true,
            'subscription.razorpay' => ['key_id' => 'rzp_test_key', 'key_secret' => self::KEY_SECRET, 'webhook_secret' => self::WEBHOOK_SECRET],
        ]);
    }

    private function creator(string $name = 'Riya Sen', ?string $phone = '+91 98765 43210'): Influencer
    {
        $user = User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@creators.test']);
        $user->forceFill(['account_type' => 'creator', 'phone' => $phone])->save();

        $creator = new Influencer(['name' => $name, 'category' => 'Food', 'country' => 'IN', 'status' => Influencer::LISTED]);
        $creator->user()->associate($user);
        $creator->save();

        SocialProfile::create([
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'username' => 'riya.eats',
            'profile_url' => 'https://www.instagram.com/riya.eats', 'followers' => 5000, 'data_source' => 'self_reported', 'provider' => 'Self-reported',
        ]);

        return $creator;
    }

    private function subscribe(Company|User $subscriber, string $status = Subscription::ACTIVE, ?\DateTimeInterface $until = null, ?string $gatewayId = null): Subscription
    {
        return Subscription::create([
            ($subscriber instanceof Company ? 'company_id' : 'user_id') => $subscriber->id,
            'status' => $status, 'gateway' => $gatewayId ? 'razorpay' : 'manual', 'gateway_subscription_id' => $gatewayId,
            'price_minor' => 99900, 'gst_minor' => 17982, 'total_minor' => 117882, 'currency' => 'INR',
            'current_period_start' => now()->subDay(), 'current_period_end' => $until ?? now()->addDays(20),
        ]);
    }

    private function profileId(Influencer $creator): int
    {
        return $creator->profiles()->value('id');
    }

    private function webhook(array $payload, string $eventId = 'evt_1', ?string $signature = null)
    {
        $body = json_encode($payload);
        $signature ??= hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
        ], $body);
    }

    private function paidEvent(string $orderId, string $paymentId = 'pay_1', int $amount = 117882, string $event = 'payment.captured'): array
    {
        return [
            'event' => $event,
            'payload' => ['payment' => ['entity' => ['id' => $paymentId, 'order_id' => $orderId, 'amount' => $amount, 'status' => 'captured']]],
        ];
    }

    private function confirmBody(string $orderId, string $paymentId): array
    {
        return [
            'razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => hash_hmac('sha256', $orderId.'|'.$paymentId, self::KEY_SECRET),
        ];
    }

    // ---- Masking ----

    public function test_nothing_is_locked_until_enforcement_is_switched_on(): void
    {
        config(['subscription.enforced' => false]);
        $creator = $this->creator();
        Sanctum::actingAs($this->createMember($this->createCompany()));

        $this->getJson('/api/v1/influencers')->assertOk()
            ->assertJsonPath('data.0.username', 'riya.eats')->assertJsonPath('data.0.locked', false);
        $this->getJson('/api/v1/influencers/'.$this->profileId($creator))->assertOk();
    }

    public function test_an_unsubscribed_brand_sees_masked_cards_and_cannot_open_a_profile(): void
    {
        $creator = $this->creator();
        Sanctum::actingAs($this->createMember($this->createCompany()));

        $card = $this->getJson('/api/v1/influencers')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertTrue($card['locked']);
        $this->assertSame('XXXxx', $card['username']);
        $this->assertNull($card['profile_url']);
        $this->assertSame(['email' => 'XXXxx', 'phone' => 'XXXxx'], $card['contact']);
        $this->assertSame('Riya Sen', $card['name']); // the name stays visible
        // Nothing real leaks anywhere in the card, not even in a field the page does not show.
        $this->assertStringNotContainsString('riya.eats', json_encode($card));
        $this->assertStringNotContainsString('creators.test', json_encode($card));
        $this->assertStringNotContainsString('98765', json_encode($card));

        $this->getJson('/api/v1/influencers/'.$this->profileId($creator))
            ->assertForbidden()->assertJsonPath('code', 'subscription_required');
    }

    public function test_a_hidden_channel_name_cannot_be_found_by_typing_it(): void
    {
        $this->creator();
        $company = $this->createCompany();
        $member = $this->createMember($company);

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/influencers?q=riya.eats')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/influencers?q=Riya')->assertOk()->assertJsonCount(1, 'data');

        $this->subscribe($company);
        Sanctum::actingAs($this->createMember($company)); // a fresh user, so nothing is cached
        $this->getJson('/api/v1/influencers?q=riya.eats')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_subscribed_brand_sees_everything_and_the_whole_team_is_covered(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany();
        $this->subscribe($company);

        foreach ([$this->createAdmin($company), $this->createMember($company)] as $member) {
            Sanctum::actingAs($member);
            $card = $this->getJson('/api/v1/influencers')->assertOk()->json('data.0');
            $this->assertFalse($card['locked']);
            $this->assertSame('riya.eats', $card['username']);
            $this->assertSame('riya.sen@creators.test', $card['contact']['email']);
            $this->assertSame('+91 98765 43210', $card['contact']['phone']);
            $this->assertSame('https://www.instagram.com/riya.eats', $card['profile_url']);
            $this->getJson('/api/v1/influencers/'.$this->profileId($creator))->assertOk();
        }
    }

    public function test_access_follows_the_paid_period_cancellation_and_the_grace_days(): void
    {
        $creator = $this->creator();
        $id = $this->profileId($creator);
        $try = function (Subscription $subscription, int $expected) use ($id) {
            $company = $subscription->company;
            Sanctum::actingAs($this->createMember($company));
            $this->getJson("/api/v1/influencers/{$id}")->assertStatus($expected);
        };

        // Active, and cancelled but still inside the paid period: access.
        $try($this->subscribe($this->createCompany('A')), 200);
        $try($this->subscribe($this->createCompany('B'), Subscription::CANCELLED), 200);
        // A failed renewal gets a few days of grace, then access ends.
        $try($this->subscribe($this->createCompany('C'), Subscription::PAST_DUE, now()->subDay()), 200);
        $try($this->subscribe($this->createCompany('D'), Subscription::PAST_DUE, now()->subDays(10)), 403);
        // The paid period is over, or payment never completed.
        $try($this->subscribe($this->createCompany('E'), Subscription::ACTIVE, now()->subMinute()), 403);
        $try($this->subscribe($this->createCompany('F'), Subscription::CREATED), 403);
        $try($this->subscribe($this->createCompany('G'), Subscription::EXPIRED), 403);
    }

    public function test_the_super_admin_always_has_access(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.status', 'none');
    }

    public function test_a_locked_brand_does_not_see_the_creators_email_even_after_they_accept(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany();
        $brand = $this->createMember($company);

        $this->subscribe($company);
        Sanctum::actingAs($brand);
        $id = $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => 'Hello', 'message' => 'Hi'])->assertCreated()->json('data.id');
        Sanctum::actingAs($creator->user);
        $this->patchJson("/api/v1/creator/requests/{$id}", ['decision' => 'accepted'])->assertOk();

        Sanctum::actingAs($this->createMember($company));
        $this->getJson('/api/v1/requests')->assertJsonPath('data.0.creator.email', 'riya.sen@creators.test')
            ->assertJsonPath('data.0.creator.username', 'riya.eats');

        Subscription::where('company_id', $company->id)->update(['status' => Subscription::EXPIRED]);
        Sanctum::actingAs($this->createMember($company));
        $this->getJson('/api/v1/requests')->assertJsonPath('data.0.creator.email', null)
            ->assertJsonPath('data.0.creator.username', 'XXXxx');
    }

    // ---- Creators browse brands ----

    public function test_a_creator_browses_brands_with_the_same_masking(): void
    {
        $creator = $this->creator();
        $acme = $this->createCompany('Acme Foods');
        $acme->forceFill(['phone' => '+91 90000 11111', 'channel_name' => '@acmefoods', 'industry' => 'Food & beverage', 'country' => 'IN', 'website' => 'https://acme.test'])->save();
        $this->createAdmin($acme, ['email' => 'owner@acme.test']);
        $pending = $this->createCompany('Pending Co');
        $pending->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();
        $this->createAdmin($pending);
        $suspended = $this->createCompany('Suspended Co');
        $suspended->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($creator->user);
        $list = $this->getJson('/api/v1/creator/brands')->assertOk()->assertJsonCount(1, 'data'); // approved and active only
        $list->assertJsonPath('data.0.name', 'Acme Foods')->assertJsonPath('data.0.industry', 'Food & beverage')
            ->assertJsonPath('data.0.locked', true)
            ->assertJsonPath('data.0.contact.email', 'XXXxx')->assertJsonPath('data.0.contact.phone', 'XXXxx')
            ->assertJsonPath('data.0.contact.channel_name', 'XXXxx')->assertJsonPath('data.0.contact.website', 'XXXxx');
        $this->getJson('/api/v1/creator/brands/'.$acme->id)->assertForbidden()->assertJsonPath('code', 'subscription_required');

        $this->subscribe($creator->user);
        Sanctum::actingAs(User::findOrFail($creator->user_id)); // a fresh user object
        $this->getJson('/api/v1/creator/brands')->assertOk()
            ->assertJsonPath('data.0.locked', false)
            ->assertJsonPath('data.0.contact.email', 'owner@acme.test')
            ->assertJsonPath('data.0.contact.phone', '+91 90000 11111')
            ->assertJsonPath('data.0.contact.channel_name', '@acmefoods');
        $this->getJson('/api/v1/creator/brands/'.$acme->id)->assertOk()->assertJsonPath('data.contact.website', 'https://acme.test');
        $this->getJson('/api/v1/creator/brands/'.$pending->id)->assertNotFound();
    }

    public function test_the_brand_directory_filters_and_is_for_creators_only(): void
    {
        $creator = $this->creator();
        foreach ([['Acme Foods', 'Food & beverage', 'IN'], ['Zed Tech', 'Technology', 'US']] as [$name, $industry, $country]) {
            $company = $this->createCompany($name);
            $company->forceFill(['industry' => $industry, 'country' => $country])->save();
        }

        Sanctum::actingAs($creator->user);
        $this->getJson('/api/v1/creator/brands?q=zed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Zed Tech');
        $this->getJson('/api/v1/creator/brands?industry=Technology')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/creator/brands?country=in')->assertOk()->assertJsonPath('data.0.name', 'Acme Foods');
        $this->getJson('/api/v1/creator/brands/filters')->assertOk()->assertJsonPath('data.industries', ['Food & beverage', 'Technology']);

        Sanctum::actingAs($this->createAdmin($this->createCompany('Third')));
        $this->getJson('/api/v1/creator/brands')->assertForbidden();
    }

    // ---- Plan, checkout, verification ----

    public function test_the_plan_is_999_plus_18_percent_gst(): void
    {
        Sanctum::actingAs($this->createAdmin($this->createCompany()));

        $plan = $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.active', false)->assertJsonPath('data.payments_ready', true)->json('data.plan');
        $this->assertEquals(999, $plan['price']);
        $this->assertEquals(179.82, $plan['gst']);
        $this->assertEquals(1178.82, $plan['total']);
        $this->assertSame(18, $plan['gst_percent']);
    }

    public function test_only_someone_who_can_manage_billing_can_pay_for_a_company(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_123'])]);
        $company = $this->createCompany();

        Sanctum::actingAs($this->createMember($company));
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.can_manage', false);
        $this->postJson('/api/v1/subscription/checkout')->assertForbidden();

        $admin = $this->createAdmin($company);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/subscription/checkout')->assertOk()
            ->assertJsonPath('data.order_id', 'order_123')->assertJsonPath('data.key_id', 'rzp_test_key')
            ->assertJsonPath('data.amount', 117882)->assertJsonPath('data.prefill.email', $admin->email);

        $this->assertDatabaseHas('subscriptions', ['company_id' => $company->id, 'status' => 'created', 'gateway_subscription_id' => 'order_123', 'total_minor' => 117882]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/orders') && $request['amount'] === 117882 && $request['currency'] === 'INR');
    }

    public function test_checkout_says_so_when_payments_are_not_set_up(): void
    {
        config(['subscription.razorpay.key_secret' => null]);
        Sanctum::actingAs($this->createAdmin($this->createCompany()));

        $this->getJson('/api/v1/subscription')->assertJsonPath('data.payments_ready', false);
        $this->postJson('/api/v1/subscription/checkout')->assertStatus(503);
    }

    public function test_a_verified_payment_gives_a_month_of_access_once_and_creates_an_invoice(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany();
        $this->subscribe($company, Subscription::CREATED, null, 'order_123');
        Subscription::where('company_id', $company->id)->update(['current_period_start' => null, 'current_period_end' => null]);
        $admin = $this->createAdmin($company);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/influencers/'.$this->profileId($creator))->assertForbidden();

        $payload = $this->confirmBody('order_123', 'pay_1');
        $this->postJson('/api/v1/subscription/confirm', $payload)->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.active', true)
            ->assertJsonPath('data.payments.0.total', 1178.82)->assertJsonPath('data.payments.0.gst', 179.82);
        $this->postJson('/api/v1/subscription/confirm', $payload)->assertOk(); // a retry changes nothing

        $row = Subscription::where('company_id', $company->id)->first();
        $this->assertTrue($row->current_period_end->between(now()->addDays(27), now()->addDays(32)));
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseHas('subscription_payments', ['gateway_payment_id' => 'pay_1', 'price_minor' => 99900, 'gst_minor' => 17982, 'total_minor' => 117882]);
        $this->assertMatchesRegularExpression('/^BM-\d{4}-\d{6}$/', \App\Domain\Subscriptions\Models\SubscriptionPayment::first()->invoice_number);

        Sanctum::actingAs($this->createMember($company));
        $this->getJson('/api/v1/influencers/'.$this->profileId($creator))->assertOk();
    }

    public function test_a_forged_or_misdirected_payment_confirmation_is_rejected(): void
    {
        $company = $this->createCompany();
        $this->subscribe($company, Subscription::CREATED, null, 'order_123');
        Sanctum::actingAs($this->createAdmin($company));

        $bad = ['razorpay_order_id' => 'order_123', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged'];
        $this->postJson('/api/v1/subscription/confirm', $bad)->assertUnprocessable()->assertJsonValidationErrors('razorpay_signature');

        // A valid signature for somebody else's order is not this company's payment.
        $this->postJson('/api/v1/subscription/confirm', $this->confirmBody('order_other', 'pay_9'))
            ->assertUnprocessable()->assertJsonValidationErrors('razorpay_order_id');

        $this->postJson('/api/v1/subscription/confirm', [])->assertUnprocessable();
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->assertSame('created', Subscription::where('company_id', $company->id)->value('status'));
    }

    public function test_a_creator_pays_for_themselves(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_creator'])]);
        $creator = $this->creator();

        Sanctum::actingAs($creator->user);
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.can_manage', true);
        $this->postJson('/api/v1/subscription/checkout')->assertOk()->assertJsonPath('data.order_id', 'order_creator');
        $this->assertDatabaseHas('subscriptions', ['user_id' => $creator->user_id, 'gateway_subscription_id' => 'order_creator']);
    }

    public function test_paying_again_while_active_adds_a_month_to_the_end_of_the_period(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_next'])]);
        $company = $this->createCompany();
        $this->subscribe($company, Subscription::ACTIVE, now()->addDays(10), 'order_old');
        $admin = $this->createAdmin($company);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/subscription/checkout')->assertOk();
        $this->assertSame('active', Subscription::where('company_id', $company->id)->value('status')); // still unlocked meanwhile

        $this->postJson('/api/v1/subscription/confirm', $this->confirmBody('order_next', 'pay_next'))->assertOk();
        $end = Subscription::where('company_id', $company->id)->first()->current_period_end;
        $this->assertTrue($end->between(now()->addDays(37), now()->addDays(42)), 'a month is added to the days that were left');
    }

    // ---- Webhooks ----

    public function test_a_signed_payment_webhook_extends_access_and_a_replay_changes_nothing(): void
    {
        $company = $this->createCompany();
        $this->subscribe($company, Subscription::ACTIVE, now()->subDays(10), 'order_123');

        $this->webhook($this->paidEvent('order_123'))->assertOk();
        $this->webhook($this->paidEvent('order_123'))->assertOk(); // Razorpay retried the same event
        $this->webhook($this->paidEvent('order_123', 'pay_1', 117882, 'order.paid'), 'evt_other')->assertOk(); // same payment, another event

        $subscription = Subscription::where('company_id', $company->id)->first();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->current_period_end->isFuture());
        $this->assertTrue($subscription->grantsAccess());
        $this->assertDatabaseCount('subscription_payments', 1); // one payment, one invoice
        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_the_confirmation_and_the_webhook_do_not_double_count_a_payment(): void
    {
        $company = $this->createCompany();
        $this->subscribe($company, Subscription::CREATED, null, 'order_123');
        Subscription::where('company_id', $company->id)->update(['current_period_start' => null, 'current_period_end' => null]);

        Sanctum::actingAs($this->createAdmin($company));
        $this->postJson('/api/v1/subscription/confirm', $this->confirmBody('order_123', 'pay_1'))->assertOk();
        $this->webhook($this->paidEvent('order_123', 'pay_1'))->assertOk();

        $end = Subscription::where('company_id', $company->id)->first()->current_period_end;
        $this->assertTrue($end->between(now()->addDays(27), now()->addDays(32)));
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_a_webhook_without_a_valid_signature_is_refused_and_changes_nothing(): void
    {
        $company = $this->createCompany();
        $this->subscribe($company, Subscription::EXPIRED, now()->subDays(10), 'order_123');

        $this->webhook($this->paidEvent('order_123'), 'evt_1', 'forged')->assertStatus(400);
        $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertStatus(400);

        $this->assertSame('expired', Subscription::where('company_id', $company->id)->value('status'));
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_payments_for_other_software_on_the_same_razorpay_account_are_ignored(): void
    {
        $company = $this->createCompany();
        $this->subscribe($company, Subscription::EXPIRED, now()->subDays(10), 'order_123');

        $this->webhook($this->paidEvent('order_from_the_crm', 'pay_crm'))->assertOk();
        $this->webhook(['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_x', 'order_id' => 'order_123', 'status' => 'failed']]]], 'e2')->assertOk();

        $this->assertSame('expired', Subscription::where('company_id', $company->id)->value('status'));
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    // ---- Contact details ----

    public function test_creators_and_brands_save_the_contact_details_that_a_subscription_unlocks(): void
    {
        $creator = $this->creator('Riya Sen', null);
        Sanctum::actingAs($creator->user);
        $this->putJson('/api/v1/creator/profile', ['name' => 'Riya Sen', 'category' => 'Food', 'country' => 'IN', 'phone' => '+91 98765 43210'])
            ->assertOk()->assertJsonPath('data.phone', '+91 98765 43210')->assertJsonPath('data.email', 'riya.sen@creators.test');
        $this->putJson('/api/v1/creator/profile', ['name' => 'Riya Sen', 'phone' => 'call me maybe'])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $company = $this->createCompany();
        Sanctum::actingAs($this->createAdmin($company));
        $this->putJson('/api/v1/company', [
            'name' => 'Acme', 'timezone' => 'Asia/Kolkata', 'phone' => '+91 90000 11111', 'channel_name' => '@acme', 'industry' => 'Technology',
        ])->assertOk()->assertJsonPath('data.phone', '+91 90000 11111')->assertJsonPath('data.industry', 'Technology');
        $this->putJson('/api/v1/company', ['name' => 'Acme', 'timezone' => 'Asia/Kolkata', 'industry' => 'Not a real industry'])
            ->assertUnprocessable()->assertJsonValidationErrors('industry');
    }

    public function test_the_grant_command_gives_and_removes_access(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany();
        $member = $this->createMember($company, attributes: ['email' => 'brand@acme.test']);

        $this->artisan('subscription:grant brand@acme.test --days=10')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['company_id' => $company->id, 'status' => 'active', 'gateway' => 'manual']);
        Sanctum::actingAs(User::findOrFail($member->id));
        $this->getJson('/api/v1/influencers/'.$this->profileId($creator))->assertOk();

        $this->artisan('subscription:grant brand@acme.test --revoke')->assertSuccessful();
        Sanctum::actingAs(User::findOrFail($member->id));
        $this->getJson('/api/v1/influencers/'.$this->profileId($creator))->assertForbidden();

        $this->artisan('subscription:grant nobody@acme.test')->assertFailed();
    }
}
