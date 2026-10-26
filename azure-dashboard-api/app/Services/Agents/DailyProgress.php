<?php

namespace App\Services\Agents;

/**
 * Datos del día de un work item: PRs/ramas enlazados y commits realizados hoy.
 */
final class DailyProgress
{
    /**
     * @param  array{id: int, title: string, type: string, state: string}  $workItem
     * @param  array<int, array>  $pullRequests
     * @param  array<int, array{repository: string, name: string}>  $branches
     * @param  array<int, array{commitId: string, message: string, author: string, date: string, time: string, repository: string, files: string[]}>  $commits
     * @param  string[]  $warnings
     */
    public function __construct(
        public readonly array $workItem,
        public readonly string $date,
        public readonly array $pullRequests,
        public readonly array $branches,
        public readonly array $commits,
        public readonly array $warnings = [],
    ) {}

    public function hasCommits(): bool
    {
        return $this->commits !== [];
    }

    public function hasLinks(): bool
    {
        return $this->pullRequests !== [] || $this->branches !== [];
    }

    public function toArray(): array
    {
        return [
            'work_item' => $this->workItem,
            'date' => $this->date,
            'pull_requests' => $this->pullRequests,
            'branches' => $this->branches,
            'commits' => $this->commits,
            'warnings' => $this->warnings,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['work_item'],
            $data['date'],
            $data['pull_requests'] ?? [],
            $data['branches'] ?? [],
            $data['commits'] ?? [],
            $data['warnings'] ?? [],
        );
    }
}
