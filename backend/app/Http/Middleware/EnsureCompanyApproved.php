<?php

namespace App\Http\Middleware;

use App\Domain\Approvals\ApprovalStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brand features (search, shortlists, requests) open once the platform team has
 * approved the company. Team, settings and billing-style pages stay reachable so a
 * new company can set itself up while it waits. Run after `tenant`.
 */
class EnsureCompanyApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->membership?->company;

        if ($company && ! $company->isApproved()) {
            abort(403, $company->approval_status === ApprovalStatus::REJECTED
                ? 'Your company was not approved. See the note on your dashboard.'
                : 'Your company is waiting for approval by the BrandMate team.');
        }

        return $next($request);
    }
}
