<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureDevOpsService;
use Illuminate\Http\JsonResponse;

class AzureRepositoryController extends Controller
{
    protected $azureDevOpsService;

    public function __construct(AzureDevOpsService $azureDevOpsService)
    {
        $this->azureDevOpsService = $azureDevOpsService;
    }

    public function index(): JsonResponse
    {
        $repos = $this->azureDevOpsService->getRepositories();

        if (isset($repos['error'])) {
            return response()->json(['message' => $repos['error']], 500);
        }

        return response()->json($repos);
    }

    public function branches($repositoryId): JsonResponse
    {
        $branches = $this->azureDevOpsService->getBranches($repositoryId);

        if (isset($branches['error'])) {
            return response()->json(['message' => $branches['error']], 500);
        }

        return response()->json($branches);
    }


    public function pullRequests($repositoryId): JsonResponse
    {
        $prs = $this->azureDevOpsService->getActivePullRequests($repositoryId);

        if (isset($prs['error'])) {
            return response()->json(['message' => $prs['error']], 500);
        }

        return response()->json($prs);
    }
}