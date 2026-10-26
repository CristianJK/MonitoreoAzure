<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureDevOpsService;
use Illuminate\Http\JsonResponse;

class AzureAgentController extends Controller
{
    protected $azureService;

    public function __construct(AzureDevOpsService $azureService)
    {
        $this->azureService = $azureService;
    }

    public function index(): JsonResponse
    {
        $agents = $this->azureService->getAgentsStatus();

        if (isset($agents['error'])) {
            return $this->azureError($agents);
        }

        return response()->json($agents);
    }
}
