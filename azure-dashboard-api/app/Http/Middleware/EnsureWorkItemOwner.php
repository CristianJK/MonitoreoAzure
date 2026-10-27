<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Controller;
use App\Services\WorkItemOwnership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas de escritura sobre un work item ({id}): solo su responsable.
 * Se valida antes de cualquier llamada de escritura a Azure DevOps.
 */
class EnsureWorkItemOwner
{
    public function __construct(protected WorkItemOwnership $ownership) {}

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->ownership->check($request->user(), (int) $request->route('id'));

        if (is_array($result)) {
            return Controller::azureErrorResponse($result);
        }

        if (! $result) {
            return response()->json(['message' => WorkItemOwnership::DENIED_MESSAGE, 'code' => 'not_owner'], 403);
        }

        return $next($request);
    }
}
