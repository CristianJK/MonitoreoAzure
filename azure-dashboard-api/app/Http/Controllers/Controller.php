<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Respuesta para un resultado ['error' => ..., 'status' => ...] de AzureDevOpsService.
     * Un 400 de Azure (regla de negocio/validación) se devuelve como 422.
     */
    protected function azureError(array $result): JsonResponse
    {
        $status = match ($result['status'] ?? 500) {
            400 => 422,
            404 => 404,
            409 => 409,
            default => 500,
        };

        return response()->json(['message' => $result['error']], $status);
    }
}
