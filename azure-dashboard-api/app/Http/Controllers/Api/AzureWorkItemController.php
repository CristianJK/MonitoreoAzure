<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureDevOpsService;
use App\Services\WorkItemOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AzureWorkItemController extends Controller
{
    protected $azureDevOpsService;

    public function __construct(AzureDevOpsService $azureDevOpsService)
    {
        $this->azureDevOpsService = $azureDevOpsService;
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100',
            'assigned_to' => 'nullable|string|max:200',
            'state' => 'array',
            'state.*' => 'string|max:100',
            'type' => 'array',
            'type.*' => 'string|max:100',
            'search' => 'nullable|string|max:200',
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $workItems = $this->azureDevOpsService->getActiveWorkItems($page, $limit, [
            'assigned_to' => $validated['assigned_to'] ?? '@Me',
            'states' => $validated['state'] ?? [],
            'types' => $validated['type'] ?? [],
            'search' => $validated['search'] ?? null,
        ]);

        if (isset($workItems['error'])) {
            return $this->azureError($workItems);
        }

        $formattedItems = array_map(function ($item) use ($request) {
            return [
                'id' => $item['id'],
                'title' => $item['fields']['System.Title'] ?? 'Sin título',
                'state' => $item['fields']['System.State'] ?? 'Desconocido',
                'type' => $item['fields']['System.WorkItemType'] ?? '',
                'assigned_to' => $item['fields']['System.AssignedTo']['displayName'] ?? 'Sin asignar',
                'is_mine' => WorkItemOwnership::matches($request->user(), $item['fields']['System.AssignedTo'] ?? null),
            ];
        }, $workItems['items']);

        $total = $workItems['total'];

        return response()->json([
            'data' => $formattedItems,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
            'page' => $page,
            'limit' => $limit,
            'summary' => $workItems['summary'],
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $result = $this->azureDevOpsService->getWorkItemFullDetails($id);

        if (isset($result['error'])) {
            return $this->azureError($result);
        }

        // Solo informativo para la UI: las rutas de escritura vuelven a validar en Azure
        $result['permissions'] = [
            'can_edit' => WorkItemOwnership::matches($request->user(), $result['assigned_to_obj']),
        ];

        return response()->json(['data' => $result]);
    }

    public function addComment(Request $request, $id): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:10000',
        ]);

        $result = $this->azureDevOpsService->addWorkItemComment($id, $request->input('text'));

        if (isset($result['error'])) {
            return $this->azureError($result);
        }

        return response()->json(['data' => $result], 201);
    }

    public function updateState(Request $request, $id): JsonResponse
    {
        $request->validate([
            'state' => 'required|string|max:100',
            'type' => 'required|string|max:100',
        ]);

        $states = $this->azureDevOpsService->getWorkItemTypeStates($request->input('type'));
        if (isset($states['error'])) {
            return $this->azureError($states);
        }

        $request->validate([
            'state' => [Rule::in(array_column($states, 'name'))],
        ], [
            'state.in' => 'El estado no es válido para este tipo de Work Item.',
        ]);

        $result = $this->azureDevOpsService->updateWorkItemState($id, $request->input('state'));

        if (isset($result['error'])) {
            return $this->azureError($result);
        }

        return response()->json([
            'message' => 'Estado actualizado en Azure DevOps',
            'data' => $result,
        ]);
    }

    public function linkBranch(Request $request, $id): JsonResponse
    {
        // Validamos que el frontend envíe los datos necesarios
        $request->validate([
            'repository_id' => 'required|uuid',
            // Nombre de rama Git sin "refs/heads/": letras, números, . _ - y /, sin ".."
            'branch_name' => ['required', 'string', 'max:250', 'regex:#^[\w.\-]+(/[\w.\-]+)*$#u', 'not_regex:#\.\.#'],
        ]);

        $result = $this->azureDevOpsService->linkBranchToWorkItem(
            $id,
            $request->input('repository_id'),
            $request->input('branch_name')
        );

        if (isset($result['error'])) {
            return $this->azureError($result);
        }

        return response()->json([
            'message' => 'Rama enlazada correctamente al Work Item',
            'data' => $result,
        ]);
    }

    public function linkPullRequest(Request $request, $id): JsonResponse
    {
        $request->validate([
            'artifact_id' => 'required|string|max:500',
        ]);

        $result = $this->azureDevOpsService->linkPullRequestToWorkItem(
            $id,
            $request->input('artifact_id')
        );

        if (isset($result['error'])) {
            return $this->azureError($result);
        }

        return response()->json([
            'message' => 'Pull Request enlazado correctamente al Work Item',
            'data' => $result,
        ]);
    }
}
