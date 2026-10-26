<?php

namespace App\Services\Agents;

use App\Services\AzureDevOpsService;
use Carbon\CarbonImmutable;

/**
 * Recolecta de forma determinista (sin IA) los PRs y commits del día de un work item.
 * Solo se toman metadatos: mensajes de commit y rutas de archivos, nunca el código.
 */
class DailyProgressCollector
{
    public function __construct(protected AzureDevOpsService $azure) {}

    /**
     * @return DailyProgress|array ['error' => ...] si no se pudo leer el work item
     */
    public function collect(int $workItemId, ?CarbonImmutable $day = null): DailyProgress|array
    {
        $timezone = config('agents.timezone');
        $day = ($day ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();

        $workItem = $this->azure->getWorkItemById($workItemId);
        if (isset($workItem['error'])) {
            return $workItem;
        }

        $fields = $workItem['fields'] ?? [];
        $links = AzureDevOpsService::parseArtifactLinks($workItem['relations'] ?? []);
        $warnings = [];

        $pullRequests = [];
        $sources = []; // [repositoryId, repositoryName, commits[]]

        foreach ($links['pull_requests'] as $link) {
            $pr = $this->azure->getPullRequest($link['repositoryId'], $link['pullRequestId']);
            if (isset($pr['error'])) {
                $warnings[] = $pr['error'];

                continue;
            }
            $pullRequests[] = $pr;

            $commits = $this->azure->getPullRequestCommitsSince($link['repositoryId'], $link['pullRequestId'], $day);
            if (isset($commits['error'])) {
                $warnings[] = $commits['error'];

                continue;
            }
            $sources[] = [$link['repositoryId'], $pr['repository'], $commits];
        }

        // Sin PR: se revisan las ramas enlazadas directamente
        $branches = [];
        foreach ($links['branches'] as $link) {
            $repository = $this->azure->getRepositoryName($link['repositoryId']);
            $branches[] = ['repository' => $repository, 'name' => $link['branch']];

            if ($pullRequests !== []) {
                continue;
            }

            $commits = $this->azure->getBranchCommitsSince($link['repositoryId'], $link['branch'], $day);
            if (isset($commits['error'])) {
                $warnings[] = $commits['error'];

                continue;
            }
            $sources[] = [$link['repositoryId'], $repository, $commits];
        }

        return new DailyProgress(
            workItem: [
                'id' => $workItemId,
                'title' => $fields['System.Title'] ?? '',
                'type' => $fields['System.WorkItemType'] ?? '',
                'state' => $fields['System.State'] ?? '',
            ],
            date: $day->toDateString(),
            pullRequests: $pullRequests,
            branches: $branches,
            commits: $this->formatCommits($sources, $timezone),
            warnings: $warnings,
        );
    }

    protected function formatCommits(array $sources, string $timezone): array
    {
        $commits = [];

        foreach ($sources as [$repositoryId, $repository, $items]) {
            foreach ($items as $commit) {
                $commits[$commit['commitId']] ??= [
                    'commitId' => $commit['commitId'],
                    'message' => $commit['message'],
                    'author' => $commit['author'],
                    'date' => $commit['date'],
                    'time' => CarbonImmutable::parse($commit['date'])->setTimezone($timezone)->format('H:i'),
                    'repository' => $repository,
                    'repositoryId' => $repositoryId,
                ];
            }
        }

        $commits = collect($commits)
            ->sortBy('date')
            ->take(config('agents.max_commits', 20))
            ->values()
            ->all();

        foreach ($commits as &$commit) {
            $commit['files'] = $this->azure->getCommitChangedFiles(
                $commit['repositoryId'],
                $commit['commitId'],
                config('agents.max_files_per_commit', 15),
            );
            unset($commit['repositoryId']);
        }

        return $commits;
    }
}
