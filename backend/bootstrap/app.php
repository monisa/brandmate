<?php

use App\Http\Middleware\EnsureCreator;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*'); // behind Nginx / a load balancer
        $middleware->prepend(RequestId::class);
        $middleware->api(prepend: [ForceJsonResponse::class]);
        $middleware->throttleApi();

        $middleware->alias([
            'tenant' => SetTenant::class,
            'permission' => EnsurePermission::class,
            'super_admin' => EnsureSuperAdmin::class,
            'creator' => EnsureCreator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
