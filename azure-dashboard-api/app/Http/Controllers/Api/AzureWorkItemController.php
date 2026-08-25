<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureDevOpsService;
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
        $limit = $request->get('limit', 100);
        $assignedTo = $request->get('assigned_to', '@Me');

        $workItems = $this->azureDevOpsService->getActiveWorkItems($limit, $assignedTo);

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
        }, $workItems);
        return response()->json($formattedItems);
    }
}
