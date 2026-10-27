<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si Azure rechazó el PAT (vencido o revocado) se cierra la sesión y se
 * descarta el PAT: el usuario debe iniciar sesión con uno nuevo.
 */
class LogoutOnInvalidPat
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() === 401
            && ($response->getData(true)['code'] ?? null) === 'azure_pat_invalid' && $request->user()) {
            $request->user()->update(['azure_pat' => null]);
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $response;
    }
}
