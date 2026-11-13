<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Shortlists\Models\Shortlist */
class ShortlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'influencers_count' => $this->whenCounted('items'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'influencer' => new InfluencerResource($item->profile),
                'notes' => $item->notes,
                'added_at' => $item->created_at->toIso8601String(),
            ])),
        ];
    }
}
