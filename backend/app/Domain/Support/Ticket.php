<?php

namespace App\Domain\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const CLOSED = 'closed';

    protected $table = 'support_tickets';

    protected $fillable = ['subject', 'category'];

    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class, 'ticket_id')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function toPayload(bool $withReplies = false, bool $withUser = false): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'category' => $this->category,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'user' => $withUser ? ['id' => $this->user?->id, 'name' => $this->user?->name, 'email' => $this->user?->email] : null,
            'replies' => $withReplies ? $this->replies->map(fn (Reply $r) => [
                'id' => $r->id,
                'staff' => $r->staff,
                'author' => $r->staff ? 'Support' : ($withUser ? $this->user?->name : 'You'),
                'body' => $r->body,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values() : null,
        ];
    }
}
