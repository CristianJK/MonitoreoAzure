<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra cada escritura (y cada intento denegado) en audit_logs. No guarda
 * el cuerpo de la petición: solo los parámetros de la ruta.
 */
class AuditWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        try {
            $parameters = collect($request->route()?->parameters() ?? [])
                ->map(fn ($value) => is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value)
                ->all();

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => $request->method().' '.$request->route()?->uri(),
                'azure_work_item_id' => $parameters['id'] ?? null,
                'result' => match (true) {
                    $status < 400 => 'ok',
                    in_array($status, [401, 403], true) => 'denied',
                    default => 'error',
                },
                'status' => $status,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'meta' => $parameters ?: null,
            ]);
        } catch (\Throwable $e) {
            // La auditoría nunca debe tumbar la petición
            Log::warning('No se pudo registrar la auditoría', ['error' => $e->getMessage()]);
        }

        return $response;
    }
}
