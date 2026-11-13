<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Companies\Models\Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'website' => $this->website,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'phone' => $this->phone,
            'channel_name' => $this->channel_name,
            'industry' => $this->industry,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            // The first member, shown to the platform team when reviewing a new company.
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['name' => $this->owner->name, 'email' => $this->owner->email] : null),
            'members_count' => $this->whenCounted('memberships'),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
