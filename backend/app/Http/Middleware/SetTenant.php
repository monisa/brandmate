<?php

namespace App\Http\Middleware;

use App\Domain\Companies\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the company from the authenticated user's membership (never from
 * request input) and activates tenant scoping for the rest of the request.
 */
class SetTenant
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $request->user()?->membership;

        abort_if($membership === null, 403, 'You are not a member of any company.');
        abort_unless($membership->company->isActive(), 403, 'This company account is suspended.');

        $this->tenant->set($membership->company);

        return $next($request);
    }
}
