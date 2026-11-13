<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('permission:team.manage') */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        foreach ($permissions as $permission) {
            abort_unless($user && $user->hasPermission($permission), 403, 'You do not have permission to do this.');
        }

        return $next($request);
    }
}
