<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentApprovalResource;
use App\Models\AgentApproval;
use App\Models\AgentRun;
use App\Services\Agents\AgentCommentService;
use App\Services\WorkItemOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cada usuario ve y resuelve solo sus propuestas, y solo mientras siga siendo
 * el responsable del work item.
 */
class AgentCommentController extends Controller
{
    public function __construct(
        protected AgentCommentService $agent,
        protected WorkItemOwnership $ownership,
    ) {}

    /**
     * "Run Azure Agent": genera la propuesta de un WI. Nunca publica.
     */
    public function propose(Request $request, int $id): JsonResponse
    {
        $approval = $this->agent->propose($id, 'manual', null, $request->user());

        if (is_array($approval)) {
            return $this->azureError($approval);
        }

        return $this->approvalResponse($approval, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|string|max:30',
            'work_item_id' => 'nullable|integer',
        ]);

        $this->agent->expireStale();

        $approvals = AgentApproval::with('workItemMetadata')
            ->where('owner_user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('work_item_id'), fn ($q) => $q->whereHas(
                'workItemMetadata',
                fn ($m) => $m->where('azure_work_item_id', (string) $request->input('work_item_id')),
            ))
            ->latest()
            ->limit(200)
            ->get();

        return response()->json(['data' => AgentApprovalResource::collection($approvals)]);
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $this->agent->expireStale();

        $count = AgentApproval::where('status', AgentApproval::PENDING)
            ->where('owner_user_id', $request->user()->id)
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function approve(Request $request, AgentApproval $approval): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:10000',
            'force' => 'boolean',
        ]);

        if ($denied = $this->denyUnlessOwner($request, $approval)) {
            return $denied;
        }

        $result = $this->agent->approve($approval, $validated['text'], $request->user(), $validated['force'] ?? false);

        if (is_array($result)) {
            $response = $this->azureError($result);

            // 'already_published' permite al frontend ofrecer "publicar de todas formas"
            return $response->setData([...$response->getData(true), 'code' => $result['code'] ?? null]);
        }

        return $this->approvalResponse($result);
    }

    public function reject(Request $request, AgentApproval $approval): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($request, $approval)) {
            return $denied;
        }

        $result = $this->agent->reject($approval, $request->user());

        return is_array($result) ? $this->azureError($result) : $this->approvalResponse($result);
    }

    public function regenerate(Request $request, AgentApproval $approval): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($request, $approval)) {
            return $denied;
        }

        $result = $this->agent->regenerate($approval);

        return is_array($result) ? $this->azureError($result) : $this->approvalResponse($result);
    }

    /**
     * "Daily Comment Automation": encola el lote para los WIs abiertos del usuario.
     * No se permite lanzarlo sobre los work items de otra persona.
     */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate(['assigned_to' => 'nullable|string|max:200']);
        $assignedTo = strtolower($validated['assigned_to'] ?? '@me');

        if (! in_array($assignedTo, ['@me', strtolower($request->user()->email)], true)) {
            return response()->json([
                'message' => 'Solo puedes ejecutar el agente sobre tus propios work items.',
                'code' => 'not_owner',
            ], 403);
        }

        $result = $this->agent->startBatch($request->user(), 'batch');

        if (isset($result['error'])) {
            return $this->azureError($result);
        }

        return response()->json(['data' => $result], 202);
    }

    public function runs(Request $request): JsonResponse
    {
        $request->validate([
            'batch_id' => 'nullable|uuid',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $runs = AgentRun::query()
            ->where('user_id', $request->user()->id)
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->input('batch_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('run_date', $request->input('date')))
            ->when(! $request->hasAny(['batch_id', 'date']), fn ($q) => $q->latest()->limit(100))
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $runs]);
    }

    protected function approvalResponse(AgentApproval $approval, int $status = 200): JsonResponse
    {
        return response()->json(['data' => new AgentApprovalResource($approval->load('workItemMetadata'))], $status);
    }

    protected function denyUnlessOwner(Request $request, AgentApproval $approval): ?JsonResponse
    {
        $user = $request->user();

        $allowed = $approval->owner_user_id === $user->id
            ? $this->ownership->check($user, (int) $approval->workItemMetadata->azure_work_item_id)
            : false;

        if (is_array($allowed)) {
            return $this->azureError($allowed);
        }

        return $allowed ? null : response()->json(['message' => WorkItemOwnership::DENIED_MESSAGE, 'code' => 'not_owner'], 403);
    }
}
