<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Tenant-scoped: a company only ever sees its own trail.
        return AuditLogResource::collection(
            AuditLog::with('user')->latest('id')->paginate($this->perPage($request))
        );
    }
}
