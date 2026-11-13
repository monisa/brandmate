<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Audit\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'action' => $this->action,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
