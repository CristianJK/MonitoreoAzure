<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChecklistDefinition;
use App\Models\ChecklistStatus;
use App\Models\WorkItemMetadata;
use App\Services\AzureDevOpsService;
use App\Services\WorkItemChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkItemChecklistController extends Controller
{
    public function __construct(protected WorkItemChecklistService $checklist) {}

    public function show(int $id): JsonResponse
    {
        $result = $this->checklist->forWorkItem($id);

        if (isset($result['error'])) {
            return $this->azureError($result);
        }

        return response()->json(['data' => $result]);
    }

    public function update(Request $request, int $id, ChecklistDefinition $definition, AzureDevOpsService $azure): JsonResponse
    {
        if ($definition->source->value !== 'manual') {
            return response()->json(['message' => 'Este item se calcula automáticamente desde Azure DevOps.'], 422);
        }

        $data = $request->validate([
            'value' => ['nullable', ...$definition->data_type->valueRules($definition->options)],
            'status' => ['nullable', Rule::in(ChecklistStatus::STATUSES)],
            'notes' => 'nullable|string|max:2000',
        ], [
            'value.regex' => 'Debe ser un link de SharePoint (https://<empresa>.sharepoint.com/...).',
        ]);

        $user = $azure->getCurrentUser();

        return response()->json([
            'data' => $this->checklist->saveValue($id, $definition, $data, $user['displayName'] ?? null),
        ]);
    }

    /**
     * Avance del alistamiento de varios WIs (solo los que ya tienen metadatos).
     */
    public function summaries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|max:100',
            'ids.*' => 'integer',
        ]);

        $summaries = WorkItemMetadata::whereIn('azure_work_item_id', array_map('strval', $validated['ids']))
            ->get()
            ->mapWithKeys(fn ($m) => [$m->azure_work_item_id => $this->checklist->summarize($m)]);

        return response()->json(['data' => $summaries]);
    }
}
