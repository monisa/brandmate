<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Support\Reply;
use App\Domain\Support\Ticket;
use App\Http\Controllers\Controller;
use App\Mail\SupportUpdate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Support tickets for the signed-in person: they only ever see and write to their own. */
class SupportTicketController extends Controller
{
    public const CATEGORIES = ['account', 'payment', 'profile', 'chat', 'other'];

    public function index(Request $request): JsonResponse
    {
        $tickets = Ticket::where('user_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $tickets->map(fn (Ticket $t) => $t->toPayload())->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['subject' => trim((string) $request->input('subject')), 'message' => trim((string) $request->input('message'))]);
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['required', 'string', 'min:5', 'max:4000'],
            'category' => ['nullable', 'in:'.implode(',', self::CATEGORIES)],
        ]);

        $ticket = new Ticket(['subject' => $data['subject'], 'category' => $data['category'] ?? null]);
        $ticket->user_id = $request->user()->id;
        $ticket->status = Ticket::OPEN;
        $ticket->last_reply_at = now();
        $ticket->save();
        $ticket->replies()->create(['user_id' => $request->user()->id, 'staff' => false, 'body' => $data['message']]);

        self::tellSupport($ticket, $request->user(), $data['message']);

        return response()->json(['data' => $ticket->load('replies')->toPayload(true)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::where('user_id', $request->user()->id)->with('replies')->findOrFail($id);

        return response()->json(['data' => $ticket->toPayload(true)]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::where('user_id', $request->user()->id)->findOrFail($id);
        $request->merge(['body' => trim((string) $request->input('body'))]);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']]);

        $ticket->replies()->create(['user_id' => $request->user()->id, 'staff' => false, 'body' => $data['body']]);
        $ticket->forceFill(['status' => Ticket::OPEN, 'last_reply_at' => now()])->save(); // writing again reopens a closed ticket

        self::tellSupport($ticket, $request->user(), $data['body']);

        return response()->json(['data' => $ticket->load('replies')->toPayload(true)], 201);
    }

    /** The support team's inbox: the e-mail from SUPPORT_EMAIL, or every platform admin when it is not set. */
    private static function tellSupport(Ticket $ticket, User $from, string $body): void
    {
        $to = config('platform.support_email')
            ? [(string) config('platform.support_email')]
            : User::where('is_super_admin', true)->whereNull('deactivated_at')->pluck('email')->all();

        foreach ($to as $address) {
            try {
                Mail::to($address)->send(new SupportUpdate(
                    SupportUpdate::TO_SUPPORT, $ticket->id, $ticket->subject, $from->name.' <'.$from->email.'>', $body,
                    rtrim((string) config('platform.frontend_url'), '/').'/admin/support',
                ));
            } catch (Throwable $e) {
                report($e); // the ticket is saved and shows in the admin list either way
            }
        }
    }
}
