<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Chat\Models\Conversation;
use App\Domain\Chat\Models\Message;
use App\Domain\Companies\Models\Company;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Push\PushSender;
use App\Domain\Subscriptions\Entitlements;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Text chat between a brand company and a creator. Both sides must be subscribed to send, so
 * contact details cannot be traded around the subscription. Everything is reached through the
 * signed-in person's own company or creator profile, so nobody can read or write another chat.
 */
class ChatController extends Controller
{
    private const PAGE = 50;

    public function conversations(Request $request): JsonResponse
    {
        [$side, $company, $creator] = $this->actor($request);

        $list = $this->listQuery($side, $company, $creator)->get();

        return response()->json([
            'data' => $list->map(fn (Conversation $c) => $this->summary($c, $side))->values(),
            'unread_total' => (int) $list->sum('unread_count'),
        ]);
    }

    /** Just the number for the sidebar badge. */
    public function unread(Request $request): JsonResponse
    {
        [$side, $company, $creator] = $this->actor($request);

        return response()->json(['unread_total' => (int) $this->listQuery($side, $company, $creator)->get()->sum('unread_count')]);
    }

    /** Opens the chat with someone, or returns the one that already exists. */
    public function start(Request $request): JsonResponse
    {
        [$side, $company, $creator] = $this->actor($request);

        if (! Entitlements::allowsRequest($request)) {
            $this->deny('subscription_required', 'Subscribe to chat with brands and creators.', 403);
        }

        if ($side === Conversation::BRAND) {
            $data = $request->validate(['creator_id' => ['required', 'integer']]);
            $target = Influencer::visibleToBrands()->findOrFail($data['creator_id']);
            if (! Entitlements::creatorAllows($target)) {
                $this->deny('counterpart_not_subscribed', 'This creator has not subscribed yet, so you cannot message them yet.', 422);
            }
            $conversation = Conversation::firstOrCreate(['company_id' => $company->id, 'influencer_id' => $target->id]);
        } else {
            $data = $request->validate(['company_id' => ['required', 'integer']]);
            $target = Company::where('approval_status', ApprovalStatus::APPROVED)->where('status', 'active')->findOrFail($data['company_id']);
            if (! Entitlements::companyAllows($target->id)) {
                $this->deny('counterpart_not_subscribed', 'This brand has not subscribed yet, so you cannot message them yet.', 422);
            }
            $conversation = Conversation::firstOrCreate(['company_id' => $target->id, 'influencer_id' => $creator->id]);
        }

        $found = $this->listQuery($side, $company, $creator)->findOrFail($conversation->id);

        return response()->json(['data' => $this->summary($found, $side)], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Messages in a conversation, oldest first. With `after=<id>` only newer ones are returned,
     * which is how the page polls. Fetching them marks them as read for this side.
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        [$side, $company, $creator] = $this->actor($request);
        $conversation = $this->mine($side, $company, $creator)->with(['company', 'influencer'])->findOrFail($id);
        $after = max(0, (int) $request->query('after', 0));

        $messages = $after > 0
            ? $conversation->messages()->with('sender')->where('id', '>', $after)->orderBy('id')->limit(100)->get()
            : $conversation->messages()->with('sender')->latest('id')->limit(self::PAGE)->get()->reverse()->values();

        if ($messages->isNotEmpty()) {
            $column = Conversation::readColumn($side);
            $newest = (int) $messages->max('id');
            if ($newest > $conversation->{$column}) {
                $conversation->forceFill([$column => $newest])->save();
            }
        }

        $block = $this->sendBlock($request, $side, $conversation);

        return response()->json([
            'data' => $messages->map(fn (Message $m) => $this->messageArray($m, $side))->values(),
            'meta' => ['can_send' => $block === null, 'reason' => $block['code'] ?? null],
        ]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        [$side, $company, $creator] = $this->actor($request);
        $conversation = $this->mine($side, $company, $creator)->with(['company', 'influencer'])->findOrFail($id);

        if ($block = $this->sendBlock($request, $side, $conversation)) {
            $this->deny($block['code'], $block['message'], $block['code'] === 'subscription_required' ? 403 : 422);
        }

        $request->merge(['body' => trim((string) $request->input('body'))]);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $conversation->messages()->create([
            'sender_user_id' => $request->user()->id,
            'sender_side' => $side,
            'body' => $data['body'],
            'created_at' => now(),
        ]);
        // What you just wrote counts as read for your own side.
        $conversation->forceFill([Conversation::readColumn($side) => $message->id, 'last_message_at' => now()])->save();

        $this->push($conversation, $side, $request->user()->id, $message->body);

        return response()->json(['data' => $this->messageArray($message->setRelation('sender', $request->user()), $side)], 201);
    }

    /** Tells the other side's browsers and phones, even when the site is closed. */
    private function push(Conversation $conversation, string $side, int $senderId, string $body): void
    {
        if (! PushSender::configured()) {
            return;
        }

        if ($side === Conversation::BRAND) {
            $recipients = [$conversation->influencer->user_id];
            $title = $conversation->company->name;
        } else {
            $recipients = $conversation->company->memberships()->pluck('user_id')->all();
            $title = $conversation->influencer->name;
        }

        PushSender::notify(
            collect($recipients)->filter()->reject(fn ($id) => $id === $senderId)->values(),
            $title,
            Str::limit($body, 120),
            '/messages?c='.$conversation->id,
        );
    }

    /**
     * Who the signed-in person is in chat. A creator speaks for themselves; a brand member speaks
     * for their company and needs the same permission as sending requests.
     *
     * @return array{0: string, 1: ?Company, 2: ?Influencer}
     */
    private function actor(Request $request): array
    {
        $user = $request->user();

        if ($user->isCreator()) {
            $creator = $user->creatorProfile;
            abort_unless($creator && $creator->isApproved(), 403, 'Your profile must be approved before you can use chat.');

            return [Conversation::CREATOR, null, $creator];
        }

        $company = $user->membership?->company;
        abort_unless($company && $user->hasPermission('request.manage'), 403, 'You cannot use chat.');
        abort_unless($company->isApproved() && $company->isActive(), 403, 'Your company must be approved before you can use chat.');

        return [Conversation::BRAND, $company, null];
    }

    private function mine(string $side, ?Company $company, ?Influencer $creator): Builder
    {
        return Conversation::query()->when(
            $side === Conversation::BRAND,
            fn ($q) => $q->where('company_id', $company->id),
            fn ($q) => $q->where('influencer_id', $creator->id),
        );
    }

    /** Conversations for this side with unread counts and the newest message's text. */
    private function listQuery(string $side, ?Company $company, ?Influencer $creator): Builder
    {
        $column = Conversation::readColumn($side);

        return $this->mine($side, $company, $creator)
            ->with(['company', 'influencer.profiles'])
            ->withCount(['messages as unread_count' => fn ($q) => $q
                ->where('sender_side', '!=', $side)
                ->whereColumn('messages.id', '>', 'conversations.'.$column)])
            ->addSelect([
                'last_body' => Message::select('body')->whereColumn('conversation_id', 'conversations.id')->latest('id')->limit(1),
                'last_side' => Message::select('sender_side')->whereColumn('conversation_id', 'conversations.id')->latest('id')->limit(1),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('conversations.id');
    }

    /**
     * Why this person cannot send right now, or null when they can: they need a subscription,
     * and so does the person they are writing to.
     *
     * @return array{code: string, message: string}|null
     */
    private function sendBlock(Request $request, string $side, Conversation $conversation): ?array
    {
        if (! Entitlements::allowsRequest($request)) {
            return ['code' => 'subscription_required', 'message' => 'Subscribe to send messages.'];
        }

        $counterpartSubscribed = $side === Conversation::BRAND
            ? Entitlements::creatorAllows($conversation->influencer)
            : Entitlements::companyAllows($conversation->company_id);

        return $counterpartSubscribed
            ? null
            : ['code' => 'counterpart_not_subscribed', 'message' => 'They have not subscribed, so you cannot message them right now.'];
    }

    /** @return array<string, mixed> */
    private function summary(Conversation $c, string $side): array
    {
        $counterpart = $side === Conversation::BRAND
            ? ['kind' => 'creator', 'id' => $c->influencer_id, 'name' => $c->influencer->name, 'avatar_url' => $c->influencer->profiles->sortBy('id')->first()?->avatar_url]
            : ['kind' => 'brand', 'id' => $c->company_id, 'name' => $c->company->name, 'avatar_url' => null];

        return [
            'id' => $c->id,
            'counterpart' => $counterpart,
            'unread_count' => (int) ($c->unread_count ?? 0),
            'last_message' => ($c->last_body ?? null) === null ? null : [
                'body' => Str::limit((string) $c->last_body, 120),
                'from_me' => $c->last_side === $side,
            ],
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function messageArray(Message $m, string $side): array
    {
        return [
            'id' => $m->id,
            'body' => $m->body,
            'from_me' => $m->sender_side === $side,
            'sender' => $m->sender?->name,
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }

    private function deny(string $code, string $message, int $status): never
    {
        abort(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
