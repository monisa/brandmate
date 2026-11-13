<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Companies\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records sensitive operations (auth, team, billing, admin actions).
 */
class AuditLogger
{
    public function __construct(private TenantContext $tenant) {}

    public function log(string $action, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?int $companyId = null): AuditLog
    {
        $request = request();
        $actor ??= $request->user();

        return AuditLog::create([
            'company_id' => $companyId ?? $this->tenant->id() ?? $actor?->membership?->company_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
            'request_id' => $request->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }
}
