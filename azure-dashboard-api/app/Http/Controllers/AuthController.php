<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AzureDevOpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Inicio de sesión con el PAT personal de Azure DevOps. El PAT se valida
 * contra Azure, se guarda cifrado y la sesión viaja en la cookie de Sanctum.
 */
class AuthController extends Controller
{
    public function login(Request $request, AzureDevOpsService $azure): JsonResponse
    {
        $validated = $request->validate(['pat' => 'required|string|min:20|max:200']);
        $pat = trim($validated['pat']);

        $asUser = $azure->withPat($pat);
        $identity = $asUser->getAuthenticatedUser();

        if (isset($identity['error'])) {
            throw ValidationException::withMessages([
                'pat' => 'Azure DevOps no aceptó el PAT. Verifica que esté vigente y sea de la organización correcta.',
            ]);
        }

        $missing = $asUser->missingPatScopes();
        if ($missing) {
            throw ValidationException::withMessages([
                'pat' => 'Al PAT le faltan permisos o no tienes acceso al proyecto: '.implode(', ', $missing).'.',
            ]);
        }

        $user = User::where('azure_id', $identity['id'])
            ->orWhere('email', strtolower($identity['uniqueName']))
            ->first() ?? new User;

        $user->fill([
            'azure_id' => $identity['id'],
            'name' => $identity['displayName'],
            'email' => strtolower($identity['uniqueName']),
            'azure_pat' => $pat,
            'last_login_at' => now(),
        ])->save();

        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['data' => $this->userPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $validated = $request->validate(['agent_schedule_enabled' => 'required|boolean']);

        $request->user()->update($validated);

        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    /**
     * El PAT se conserva solo si el usuario tiene activa la ejecución
     * programada, que lo necesita para correr sin sesión.
     */
    public function logout(Request $request): Response
    {
        $user = $request->user();
        if ($user && ! $user->agent_schedule_enabled) {
            $user->update(['azure_pat' => null]);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->azure_id,
            'displayName' => $user->name,
            'uniqueName' => $user->email,
            'isAdmin' => $user->isAdmin(),
            'agentScheduleEnabled' => $user->agent_schedule_enabled,
        ];
    }
}
