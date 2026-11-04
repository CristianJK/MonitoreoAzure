<?php

namespace App\Services;

use App\Enums\ChecklistDataType;
use App\Enums\ChecklistSource;
use App\Models\ChecklistDefinition;
use App\Models\ChecklistStatus;
use App\Models\WorkItemMetadata;
use Illuminate\Support\Collection;

/**
 * Alistamiento de un work item: definiciones que le aplican agrupadas por
 * estado, con el valor registrado y los items automáticos calculados desde Azure.
 */
class WorkItemChecklistService
{
    /** @var array<int, array|null> */
    protected array $pullRequests = [];

    public function __construct(protected AzureDevOpsService $azure) {}

    public function forWorkItem(int $workItemId): array
    {
        $workItem = $this->azure->getWorkItemById($workItemId);
        if (isset($workItem['error'])) {
            return $workItem;
        }

        $type = $workItem['fields']['System.WorkItemType'] ?? '';
        $state = $workItem['fields']['System.State'] ?? '';
        $metadata = WorkItemMetadata::forWorkItem($workItemId, $type);

        $statuses = $metadata->checklistStatuses()->get()->keyBy('checklist_definition_id');

        // Las definiciones eliminadas solo se muestran si ya tenían valor en este WI
        $definitions = ChecklistDefinition::withTrashed()
            ->forType($type)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn ($d) => ! $d->trashed() || $statuses->has($d->id))
            ->values();

        $links = $this->syncAutomaticItems(
            $metadata,
            $definitions->filter(fn ($d) => ! $d->trashed() && $d->source !== ChecklistSource::Manual),
            $workItem['relations'] ?? [],
            $statuses,
        );

        $typeStates = $this->azure->getWorkItemTypeStates($type);
        $typeStates = isset($typeStates['error']) ? [] : $typeStates;

        return [
            'work_item' => ['id' => $workItemId, 'type' => $type, 'state' => $state],
            'states' => $typeStates,
            'groups' => $this->groupByState($definitions, $statuses, $links, $typeStates, $state),
            'summary' => $this->summarize($metadata),
        ];
    }

    public function saveValue(int $workItemId, ChecklistDefinition $definition, array $data, ?string $user): array
    {
        $metadata = WorkItemMetadata::forWorkItem($workItemId);

        $value = $data['value'] ?? null;
        if ($definition->data_type === ChecklistDataType::Boolean && $value !== null) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        $value = $value === '' ? null : $value;

        $previous = $metadata->checklistStatuses()->where('checklist_definition_id', $definition->id)->value('value');

        $status = ChecklistStatus::updateOrCreate(
            ['work_item_metadata_id' => $metadata->id, 'checklist_definition_id' => $definition->id],
            [
                'value' => $value === null ? null : (string) $value,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? ($value === null ? 'pending' : 'done'),
                'checked_by' => $user,
                'checked_at' => now(),
            ],
        );

        $warning = $definition->data_type === ChecklistDataType::SharepointLink
            ? $this->updateDocumentLink($workItemId, $metadata, $definition, $previous, $status->value)
            : null;

        return array_filter([
            'item' => $this->itemPayload($definition, $status, []),
            'summary' => $this->summarize($metadata),
            'warning' => $warning,
        ], fn ($v) => $v !== null);
    }

    /**
     * Enlaza en Azure (pestaña Links del WI) todos los documentos de SharePoint
     * registrados en el alistamiento que aún no estén enlazados.
     *
     * @return array{added: int, already_linked: int, total: int}|array{error: string}
     */
    public function syncDocumentLinks(int $workItemId): array
    {
        $workItem = $this->azure->getWorkItemById($workItemId);
        if (isset($workItem['error'])) {
            return $workItem;
        }

        $existing = AzureDevOpsService::hyperlinks($workItem['relations'] ?? []);
        $documents = $this->documentLinks(WorkItemMetadata::forWorkItem($workItemId, $workItem['fields']['System.WorkItemType'] ?? ''));

        $added = 0;
        $alreadyLinked = 0;

        foreach ($documents as $url => $label) {
            if (collect($existing)->contains(fn ($e) => AzureDevOpsService::sameUrl($e, $url))) {
                $alreadyLinked++;

                continue;
            }

            $result = $this->azure->addHyperlink($workItemId, $url, $label);
            if (isset($result['error'])) {
                return $result;
            }
            $result['added'] ? $added++ : $alreadyLinked++;
        }

        return ['added' => $added, 'already_linked' => $alreadyLinked, 'total' => count($documents)];
    }

    /**
     * URL => nombre del item, de los valores sharepoint_link del work item.
     *
     * @return array<string, string>
     */
    protected function documentLinks(WorkItemMetadata $metadata): array
    {
        return $metadata->checklistStatuses()
            ->whereNotNull('value')
            ->get()
            ->map(fn ($s) => [$s, ChecklistDefinition::withTrashed()->find($s->checklist_definition_id)])
            ->filter(fn ($pair) => $pair[1]?->data_type === ChecklistDataType::SharepointLink)
            ->mapWithKeys(fn ($pair) => [$pair[0]->value => $pair[1]->label])
            ->all();
    }

    /**
     * Mantiene el hyperlink del documento en Azure al cambiar el valor. Si Azure
     * falla, el valor queda guardado igual y se devuelve una advertencia.
     */
    protected function updateDocumentLink(int $workItemId, WorkItemMetadata $metadata, ChecklistDefinition $definition, ?string $previous, ?string $current): ?string
    {
        if ($previous === $current || ($previous !== null && $current !== null && AzureDevOpsService::sameUrl($previous, $current))) {
            return null;
        }

        $documents = $this->documentLinks($metadata);

        // Solo se quita si ningún otro item del alistamiento usa el mismo link
        if ($previous !== null && ! collect(array_keys($documents))->contains(fn ($u) => AzureDevOpsService::sameUrl($u, $previous))) {
            $removed = $this->azure->removeHyperlink($workItemId, $previous);
            if (isset($removed['error'])) {
                return "El valor se guardó, pero no se pudo quitar el link anterior en Azure DevOps: {$removed['error']}";
            }
        }

        if ($current !== null) {
            $added = $this->azure->addHyperlink($workItemId, $current, $definition->label);
            if (isset($added['error'])) {
                return "El valor se guardó, pero no se pudo enlazar el documento en Azure DevOps: {$added['error']}";
            }
        }

        return null;
    }

    /**
     * Resumen de avance a partir de la base de datos (sin consultar Azure).
     */
    public function summarize(WorkItemMetadata $metadata): array
    {
        $definitions = ChecklistDefinition::forType($metadata->work_item_type ?: null)->get(['id', 'blocking']);
        $statuses = $metadata->checklistStatuses()->pluck('status', 'checklist_definition_id');

        $isComplete = fn ($d) => in_array($statuses[$d->id] ?? 'pending', ['done', 'skipped'], true);

        $total = $definitions->count();
        $done = $definitions->filter($isComplete)->count();
        $blockingPending = $definitions->filter(fn ($d) => $d->blocking && ! $isComplete($d))->count();
        $ready = $total > 0 && $blockingPending === 0;

        if ($metadata->ready_to_deploy !== $ready) {
            $metadata->update(['ready_to_deploy' => $ready]);
        }

        return [
            'total' => $total,
            'done' => $done,
            'blocking_pending' => $blockingPending,
            'ready_to_deploy' => $ready,
        ];
    }

    /**
     * Calcula y guarda el valor de los items con origen automático.
     *
     * @return array<int, array> links por definition_id para mostrar en la vista
     */
    protected function syncAutomaticItems(WorkItemMetadata $metadata, Collection $definitions, array $relations, Collection $statuses): array
    {
        if ($definitions->isEmpty()) {
            return [];
        }

        $artifacts = AzureDevOpsService::parseArtifactLinks($relations);
        $pullRequests = collect($artifacts['pull_requests'])
            ->map(fn ($pr) => $this->pullRequest($pr['repositoryId'], $pr['pullRequestId']))
            ->filter()
            ->values();

        $links = [];

        foreach ($definitions as $definition) {
            [$status, $value, $itemLinks] = match ($definition->source) {
                ChecklistSource::AzurePr => $this->pullRequestItem($pullRequests),
                ChecklistSource::AzureBranch => $this->branchItem($artifacts['branches'], $pullRequests),
                ChecklistSource::AzurePipeline => $this->pipelineItem($artifacts['branches'], $pullRequests),
            };

            $links[$definition->id] = $itemLinks;

            $current = $statuses->get($definition->id);
            if ($current && $current->status === $status && $current->value === $value) {
                continue;
            }

            $statuses->put($definition->id, ChecklistStatus::updateOrCreate(
                ['work_item_metadata_id' => $metadata->id, 'checklist_definition_id' => $definition->id],
                ['status' => $status, 'value' => $value, 'checked_by' => 'system', 'checked_at' => now()],
            ));
        }

        return $links;
    }

    protected function pullRequest(string $repositoryId, int $pullRequestId): ?array
    {
        if (! array_key_exists($pullRequestId, $this->pullRequests)) {
            $pr = $this->azure->getPullRequest($repositoryId, $pullRequestId);
            $this->pullRequests[$pullRequestId] = isset($pr['error']) ? null : $pr;
        }

        return $this->pullRequests[$pullRequestId];
    }

    protected function pullRequestItem(Collection $pullRequests): array
    {
        if ($pullRequests->isEmpty()) {
            return ['pending', null, []];
        }

        return [
            'done',
            $pullRequests->map(fn ($pr) => "PR !{$pr['id']} ({$pr['status']})")->implode(', '),
            $pullRequests->map(fn ($pr) => ['label' => "PR !{$pr['id']} · {$pr['title']}", 'url' => $pr['url']])->all(),
        ];
    }

    protected function branchItem(array $branches, Collection $pullRequests): array
    {
        $names = collect($branches)->pluck('branch')
            ->merge($pullRequests->pluck('sourceBranch'))
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return ['pending', null, []];
        }

        return ['done', $names->implode(', '), []];
    }

    protected function pipelineItem(array $branches, Collection $pullRequests): array
    {
        $branch = $pullRequests->pluck('sourceBranch')->filter()->first()
            ?? collect($branches)->pluck('branch')->first();

        if (! $branch) {
            return ['pending', null, []];
        }

        $build = $this->azure->getLatestBuild($branch);
        if (! $build) {
            return ['pending', "Sin builds para {$branch}", []];
        }

        $status = match (true) {
            $build['result'] === 'succeeded' => 'done',
            in_array($build['result'], ['failed', 'partiallySucceeded', 'canceled'], true) => 'failed',
            default => 'in_progress',
        };

        $label = "{$build['definition']} #{$build['number']} (".($build['result'] ?? $build['status']).')';

        return [$status, $label, $build['url'] ? [['label' => $label, 'url' => $build['url']]] : []];
    }

    protected function groupByState(Collection $definitions, Collection $statuses, array $links, array $typeStates, string $currentState): array
    {
        $stateInfo = collect($typeStates)->keyBy('name');
        $order = collect($typeStates)->pluck('name')->flip();

        return $definitions
            ->groupBy('work_item_state')
            ->map(fn ($items, $state) => [
                'state' => $state,
                'category' => $stateInfo[$state]['category'] ?? null,
                'color' => $stateInfo[$state]['color'] ?? null,
                'is_current' => $state === $currentState,
                'order' => $order[$state] ?? null,
                'items' => $items->map(fn ($d) => $this->itemPayload($d, $statuses->get($d->id), $links[$d->id] ?? []))->values()->all(),
            ])
            // Primero los estados del flujo del tipo, luego los que no existen para este tipo
            ->sortBy(fn ($g) => $g['order'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    protected function itemPayload(ChecklistDefinition $definition, ?ChecklistStatus $status, array $links): array
    {
        return [
            'definition_id' => $definition->id,
            'code' => $definition->code,
            'label' => $definition->label,
            'description' => $definition->description,
            'data_type' => $definition->data_type->value,
            'options' => $definition->options ?? [],
            'work_item_state' => $definition->work_item_state,
            'blocking' => $definition->blocking,
            'is_required' => $definition->is_required,
            'source' => $definition->source->value,
            'is_automatic' => $definition->source !== ChecklistSource::Manual,
            'is_deleted' => $definition->trashed(),
            'status' => $status?->status ?? 'pending',
            'value' => $status?->value,
            'notes' => $status?->notes,
            'checked_by' => $status?->checked_by,
            'checked_at' => $status?->checked_at,
            'links' => $links,
        ];
    }
}
