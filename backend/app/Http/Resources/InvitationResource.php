<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Companies\Models\Invitation */
class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => ['id' => $this->role->id, 'name' => $this->role->name, 'slug' => $this->role->slug],
            'invited_by' => $this->inviter?->name,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
