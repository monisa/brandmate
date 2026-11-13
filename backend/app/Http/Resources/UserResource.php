<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->membership;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'is_super_admin' => $this->isSuperAdmin(),
            'account_type' => $this->account_type ?? 'brand',
            'company' => $membership?->company ? [
                'id' => $membership->company->id,
                'name' => $membership->company->name,
                'slug' => $membership->company->slug,
            ] : null,
            'role' => $membership?->role ? [
                'id' => $membership->role->id,
                'name' => $membership->role->name,
                'slug' => $membership->role->slug,
            ] : null,
            // For showing/hiding UI only. The API enforces every permission itself.
            'permissions' => $this->permissionSlugs(),
        ];
    }
}
