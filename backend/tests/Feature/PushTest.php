<?php

namespace Tests\Feature;

use App\Domain\Push\PushSender;
use App\Domain\Push\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.test/send/abc123';

    private function payload(): array
    {
        return ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'BPublicKeyValue', 'auth' => 'authSecret']];
    }

    public function test_the_public_key_is_only_offered_when_push_is_set_up(): void
    {
        Sanctum::actingAs(User::factory()->create());

        config(['push.public_key' => null, 'push.private_key' => null]);
        $this->getJson('/api/v1/push/key')->assertOk()->assertJsonPath('public_key', null);
        $this->assertFalse(PushSender::configured());

        config(['push.public_key' => 'pub', 'push.private_key' => 'priv']);
        $this->getJson('/api/v1/push/key')->assertOk()->assertJsonPath('public_key', 'pub');
    }

    public function test_a_browser_can_subscribe_and_unsubscribe(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/push/subscribe', $this->payload())->assertCreated();
        $this->postJson('/api/v1/push/subscribe', $this->payload())->assertCreated(); // twice is still one row
        $this->assertSame(1, PushSubscription::where('user_id', $user->id)->count());

        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_browser_moves_to_whoever_registered_it_last_and_others_cannot_remove_it(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/push/subscribe', $this->payload())->assertCreated();

        Sanctum::actingAs($second);
        $this->postJson('/api/v1/push/subscribe', $this->payload())->assertCreated();
        $this->assertSame($second->id, PushSubscription::firstOrFail()->user_id);

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(1, PushSubscription::count());
    }

    public function test_it_rejects_an_incomplete_subscription(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/push/subscribe', ['endpoint' => self::ENDPOINT])->assertUnprocessable();
    }

    public function test_sending_does_nothing_and_does_not_fail_without_keys_or_subscriptions(): void
    {
        config(['push.public_key' => null, 'push.private_key' => null]);
        PushSender::notify([1], 'Title', 'Body', '/messages');

        $this->assertTrue(true);
    }
}
