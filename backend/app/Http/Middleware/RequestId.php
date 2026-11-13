<?php

namespace App\Http\Middleware;

use App\Domain\Companies\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id that appears in logs, audit records and the
 * X-Request-Id response header, so a customer report can be traced.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never let a tenant leak from a previous request in long-lived workers.
        app(TenantContext::class)->forget();

        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
