<?php

namespace App\Domain\Creators\Models;

use App\Domain\Influencers\Models\Influencer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorPackage extends Model
{
    protected $fillable = ['influencer_id', 'platform', 'title', 'description', 'price_minor', 'currency', 'delivery_days', 'is_active'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'delivery_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price_minor / 100,
            'currency' => $this->currency,
            'delivery_days' => $this->delivery_days,
            'is_active' => $this->is_active,
        ];
    }
}
