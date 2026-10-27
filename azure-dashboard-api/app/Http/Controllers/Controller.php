<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Respuesta para un resultado ['error' => ..., 'status' => ...] de AzureDevOpsService.
     */
    protected function azureError(array $result): JsonResponse
    {
        return self::azureErrorResponse($result);
    }

    /**
     * Un 400 de Azure (regla de negocio/validación) se devuelve como 422. Un
     * 401 significa que Azure rechazó el PAT del usuario (LogoutOnInvalidPat
     * cierra la sesión).
     */
    public static function azureErrorResponse(array $result): JsonResponse
    {
        if (($result['status'] ?? null) === 401) {
            return response()->json([
                'message' => 'Azure DevOps rechazó tu PAT (venció, fue revocado o le faltan permisos). Inicia sesión con un PAT válido.',
                'code' => 'azure_pat_invalid',
            ], 401);
        }

        $status = match ($result['status'] ?? 500) {
            400 => 422,
            403 => 403,
            404 => 404,
            409 => 409,
            default => 500,
        };

        return response()->json(['message' => $result['error']], $status);
    }
}
