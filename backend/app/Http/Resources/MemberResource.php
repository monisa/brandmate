<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Companies\Models\Membership */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'role' => ['id' => $this->role->id, 'name' => $this->role->name, 'slug' => $this->role->slug],
            'status' => $this->status,
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
