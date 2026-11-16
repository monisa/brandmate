<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Support\Ticket;
use App\Http\Controllers\Controller;
use App\Mail\SupportUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/** The platform team's view of every support ticket. Super admins only (see the route group). */
class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $tickets = Ticket::with('user')
            ->when(in_array($status, [Ticket::OPEN, Ticket::ANSWERED, Ticket::CLOSED], true), fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'open' then 0 when 'answered' then 1 else 2 end")
            ->orderByDesc('last_reply_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $tickets->map(fn (Ticket $t) => $t->toPayload(false, true))->values(),
            'open_count' => Ticket::where('status', Ticket::OPEN)->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Ticket::with(['user', 'replies'])->findOrFail($id)->toPayload(true, true)]);
    }

    public function reply(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $ticket = Ticket::with('user')->findOrFail($id);
        $request->merge(['body' => trim((string) $request->input('body'))]);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']]);

        $ticket->replies()->create(['user_id' => $request->user()->id, 'staff' => true, 'body' => $data['body']]);
        $ticket->forceFill(['status' => Ticket::ANSWERED, 'last_reply_at' => now()])->save();
        $audit->log('support.replied', $ticket->user, ['ticket' => $ticket->id]);

        try {
            Mail::to($ticket->user->email)->send(new SupportUpdate(
                SupportUpdate::TO_USER, $ticket->id, $ticket->subject, 'BrandMate support', $data['body'],
                rtrim((string) config('platform.frontend_url'), '/').'/support',
            ));
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['data' => $ticket->load(['user', 'replies'])->toPayload(true, true)], 201);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([Ticket::OPEN, Ticket::CLOSED])]]);
        $ticket = Ticket::with('user')->findOrFail($id);
        $ticket->forceFill(['status' => $data['status']])->save();

        return response()->json(['data' => $ticket->toPayload(false, true)]);
    }
}
