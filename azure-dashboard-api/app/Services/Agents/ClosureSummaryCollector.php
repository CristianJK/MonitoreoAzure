<?php

namespace App\Services\Agents;

use App\Services\AzureDevOpsService;

/**
 * Recolecta de forma determinista (sin IA) los datos de cierre de un PR de un
 * work item. Solo metadatos: mensajes de commit y rutas de archivos, nunca el código.
 */
class ClosureSummaryCollector
{
    public function __construct(protected AzureDevOpsService $azure) {}

    /**
     * @param  array  $workItem  respuesta de getWorkItemById()
     * @param  array  $pullRequest  respuesta de getPullRequest()
     */
    public function collect(array $workItem, array $pullRequest, array $typeStates = []): ClosureSummary
    {
        $fields = $workItem['fields'] ?? [];
        $warnings = [];

        $commits = $this->azure->getPullRequestCommits($pullRequest['repositoryId'], $pullRequest['id']);
        if (isset($commits['error'])) {
            $warnings[] = $commits['error'];
            $commits = [];
        }

        $commits = collect($commits)
            ->take(config('agents.max_closure_commits', 60))
            ->map(fn ($c) => [
                'commitId' => $c['commitId'],
                'message' => $c['message'],
                'author' => $c['author'],
                'date' => $c['date'],
            ])
            ->values()
            ->all();

        $files = $this->azure->getPullRequestChangedFiles(
            $pullRequest['repositoryId'],
            $pullRequest['id'],
            config('agents.max_closure_files', 100),
        );

        return new ClosureSummary(
            workItem: [
                'id' => (int) $workItem['id'],
                'title' => $fields['System.Title'] ?? '',
                'type' => $fields['System.WorkItemType'] ?? '',
                'state' => $fields['System.State'] ?? '',
            ],
            dates: $this->dates($workItem, $typeStates),
            pullRequest: $pullRequest,
            commits: $commits,
            files: $files,
            warnings: $warnings,
        );
    }

    /**
     * Fechas de creación, inicio y cierre. Si el proceso no llena ActivatedDate /
     * ClosedDate, se buscan en el historial la primera transición a un estado de
     * categoría InProgress y la última a Completed.
     */
    protected function dates(array $workItem, array $typeStates): array
    {
        $fields = $workItem['fields'] ?? [];
        $activated = $fields['Microsoft.VSTS.Common.ActivatedDate'] ?? null;
        $closed = $fields['Microsoft.VSTS.Common.ClosedDate'] ?? null;

        if (! $activated || ! $closed) {
            $categories = collect($typeStates)->pluck('category', 'name');

            foreach ($this->azure->getWorkItemUpdates($workItem['id']) as $update) {
                $state = $update['fields']['System.State']['newValue'] ?? null;
                $changed = $update['fields']['System.ChangedDate']['newValue'] ?? null;
                if (! $state || ! $changed) {
                    continue;
                }

                $category = $categories[$state] ?? null;
                if ($category === 'InProgress' && ! $activated) {
                    $activated = $changed;
                }
                if ($category === 'Completed' && ! ($fields['Microsoft.VSTS.Common.ClosedDate'] ?? null)) {
                    $closed = $changed;
                }
            }
        }

        return [
            'created' => $fields['System.CreatedDate'] ?? null,
            'activated' => $activated,
            'closed' => $closed,
        ];
    }
}
