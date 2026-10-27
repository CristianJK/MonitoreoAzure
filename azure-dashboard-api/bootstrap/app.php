<?php

use App\Http\Middleware\AuditWrites;
use App\Http\Middleware\EnsureWorkItemOwner;
use App\Http\Middleware\LogoutOnInvalidPat;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sesión de Sanctum (cookie + CSRF) para las peticiones de la SPA
        $middleware->statefulApi();

        $middleware->api(prepend: [
            HandleCors::class,
        ]);
        $middleware->append(SecurityHeaders::class);

        // La API no tiene página de login propia: sin sesión responde 401
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'azure.pat' => LogoutOnInvalidPat::class,
            'work-item.owner' => EnsureWorkItemOwner::class,
            'audit' => AuditWrites::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // El PAT nunca vuelve a la sesión ni a los logs de validación
        $exceptions->dontFlash(['pat']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
