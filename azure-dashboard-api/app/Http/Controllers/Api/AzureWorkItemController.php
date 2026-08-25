<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureDevOpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AzureWorkItemController extends Controller
{
    protected $azureDevOpsService;

    public function __construct(AzureDevOpsService $azureDevOpsService)
    {
        $this->azureDevOpsService = $azureDevOpsService;
    }

    public function index(Request $request)
    {
        $limit = $request->limit ?? 10;
        $assignedTo = $request->assigned_to ?? '@Me';
        $page = $request->page ?? 1;

        $workItems = $this->azureDevOpsService->getActiveWorkItems($page, $limit, $assignedTo);

        if (isset($workItems['error'])) {
            return response()->json(['message' => $workItems['error']], 500);
        }

        $formattedItems = array_map(function ($item) {
            return [
                'id' => $item['id'],
                'title' => $item['fields']['System.Title'] ?? 'Sin título',
                'state' => $item['fields']['System.State'] ?? 'Desconocido',
                'type' => $item['fields']['System.WorkItemType'] ?? '',
                'assigned_to' => $item['fields']['System.AssignedTo']['displayName'] ?? 'Sin asignar',
            ];
        }, $workItems['items']);

        $total = $workItems['total'];

        return response()->json([
            'data' => $formattedItems,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
            'page' => (int) $page,
            'limit' => (int) $limit,
            'summary' => $workItems['summary'],
        ]);
    }

    public function linkBranch(Request $request, $id): JsonResponse
    {
        // Validamos que el frontend envíe los datos necesarios
        $request->validate([
            'repository_id' => 'required|string',
            'branch_name' => 'required|string',
        ]);

        $result = $this->azureDevOpsService->linkBranchToWorkItem(
            $id, 
            $request->input('repository_id'), 
            $request->input('branch_name')
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 500);
        }

        return response()->json([
            'message' => 'Rama enlazada correctamente al Work Item',
            'data' => $result
        ]);
    }

    public function linkPullRequest(Request $request, $id): JsonResponse
    {
        $request->validate([
            'repository_id' => 'required|string',
            'pull_request_id' => 'required|integer',
        ]);

        $result = $this->azureDevOpsService->linkPullRequestToWorkItem(
            $id, 
            $request->input('repository_id'), 
            $request->input('pull_request_id')
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 500);
        }

        return response()->json([
            'message' => 'Pull Request enlazado correctamente al Work Item',
            'data' => $result
        ]);
    }
}
