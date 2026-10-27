<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureDevOpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(protected AzureDevOpsService $azureDevOpsService) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100']);

        $users = $this->azureDevOpsService->searchUsers((string) $request->input('search', ''));

        if (isset($users['error'])) {
            return $this->azureError($users);
        }

        return response()->json(['data' => $users]);
    }
}
