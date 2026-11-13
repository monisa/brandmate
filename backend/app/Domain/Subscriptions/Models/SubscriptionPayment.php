<?php

namespace App\Domain\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One successful charge. It is also the GST invoice for that month. */
class SubscriptionPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'gst_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'paid_at' => $this->paid_at->toIso8601String(),
            'price' => $this->price_minor / 100,
            'gst' => $this->gst_minor / 100,
            'total' => $this->total_minor / 100,
            'currency' => $this->currency,
            'status' => $this->status,
        ];
    }
}
