<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChecklistDataType;
use App\Enums\ChecklistSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChecklistDefinitionRequest;
use App\Http\Resources\ChecklistDefinitionResource;
use App\Models\ChecklistDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChecklistDefinitionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'state' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:100',
        ]);

        $definitions = ChecklistDefinition::query()
            ->withCount(['statuses' => fn ($q) => $q->whereNotNull('value')])
            ->when($request->filled('state'), fn ($q) => $q->where('work_item_state', $request->input('state')))
            ->when($request->filled('type'), fn ($q) => $q->forType($request->input('type')))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ChecklistDefinitionResource::collection($definitions);
    }

    public function store(ChecklistDefinitionRequest $request): JsonResponse
    {
        $data = $this->normalize($request->validated());
        $data['code'] = $data['code'] ?? $this->uniqueCode($data['label']);
        $data['sort_order'] = $data['sort_order']
            ?? (int) ChecklistDefinition::where('work_item_state', $data['work_item_state'])->max('sort_order') + 1;

        $definition = ChecklistDefinition::create($data)->refresh();

        return (new ChecklistDefinitionResource($definition))->response()->setStatusCode(201);
    }

    public function show(ChecklistDefinition $checklistDefinition): ChecklistDefinitionResource
    {
        return new ChecklistDefinitionResource($checklistDefinition);
    }

    public function update(ChecklistDefinitionRequest $request, ChecklistDefinition $checklistDefinition): JsonResponse|ChecklistDefinitionResource
    {
        $data = $this->normalize($request->validated());

        $dataTypeChanged = $data['data_type'] !== $checklistDefinition->data_type->value;
        if ($dataTypeChanged && $checklistDefinition->statuses()->whereNotNull('value')->exists()) {
            return response()->json([
                'message' => 'No se puede cambiar el tipo de dato: ya hay Work Items con valores registrados para este item.',
                'errors' => ['data_type' => ['Ya hay valores registrados con el tipo de dato actual.']],
            ], 422);
        }

        $data['code'] = $data['code'] ?? $checklistDefinition->code;
        $checklistDefinition->update($data);

        return new ChecklistDefinitionResource($checklistDefinition);
    }

    public function destroy(ChecklistDefinition $checklistDefinition): JsonResponse
    {
        $checklistDefinition->delete();

        return response()->json(['message' => 'Item de alistamiento eliminado']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:checklist_definitions,id',
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['items'] as $item) {
                ChecklistDefinition::whereKey($item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['message' => 'Orden actualizado']);
    }

    public function dataTypes(): JsonResponse
    {
        return response()->json([
            'data' => [
                'data_types' => array_map(fn ($t) => ['value' => $t->value, 'label' => $t->label()], ChecklistDataType::cases()),
                'sources' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], ChecklistSource::cases()),
            ],
        ]);
    }

    protected function normalize(array $data): array
    {
        $data['source'] = $data['source'] ?? ChecklistSource::Manual->value;
        $data['options'] = $data['data_type'] === ChecklistDataType::Select->value
            ? array_values($data['options'] ?? [])
            : null;
        $data['applies_to_type'] = ($data['applies_to_type'] ?? null) ?: null;

        return $data;
    }

    protected function uniqueCode(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'item';
        $code = $base;
        $i = 2;

        while (ChecklistDefinition::withTrashed()->where('code', $code)->exists()) {
            $code = "{$base}_{$i}";
            $i++;
        }

        return $code;
    }
}
