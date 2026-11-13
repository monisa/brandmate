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
            'status' => $this->status,
            'members_count' => $this->whenCounted('memberships'),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
