<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A creator's or a brand company's monthly subscription. It is set only in code
 * (checkout, webhooks, a grant), never from request input.
 */
class Subscription extends Model
{
    public const CREATED = 'created';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'gst_minor' => 'integer',
            'total_minor' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * Whether the paid-for period still covers today. A cancelled subscription keeps working
     * until the period it paid for ends, and a failed renewal gets a few days of grace.
     */
    public function grantsAccess(): bool
    {
        $end = $this->current_period_end;
        if ($end === null) {
            return false;
        }

        return match ($this->status) {
            self::ACTIVE, self::CANCELLED => $end->isFuture(),
            self::PAST_DUE => $end->copy()->addDays((int) config('subscription.grace_days'))->isFuture(),
            default => false,
        };
    }
}
