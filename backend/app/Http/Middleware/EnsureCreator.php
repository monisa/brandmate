<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Creator accounts only. Brand users and admins get 403. */
class EnsureCreator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->isCreator() && $user->creatorProfile, 403, 'This area is for creator accounts.');

        return $next($request);
    }
}
