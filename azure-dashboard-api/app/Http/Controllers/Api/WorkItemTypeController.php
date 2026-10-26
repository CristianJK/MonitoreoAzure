<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureDevOpsService;
use Illuminate\Http\JsonResponse;

class WorkItemTypeController extends Controller
{
    public function __construct(protected AzureDevOpsService $azureDevOpsService) {}

    public function index(): JsonResponse
    {
        $types = $this->azureDevOpsService->getWorkItemTypes();

        if (isset($types['error'])) {
            return $this->azureError($types);
        }

        return response()->json(['data' => $types]);
    }

    public function states(string $type): JsonResponse
    {
        $states = $this->azureDevOpsService->getWorkItemTypeStates($type);

        if (isset($states['error'])) {
            return $this->azureError($states);
        }

        return response()->json(['data' => $states]);
    }
}
