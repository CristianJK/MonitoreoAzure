<?php

namespace App\Services\Agents;

use Carbon\CarbonImmutable;

/**
 * Datos para el comentario de cierre de un work item, uno por Pull Request:
 * fechas del WI y del PR, todos los commits del PR y los archivos modificados.
 */
final class ClosureSummary
{
    /**
     * @param  array{id: int, title: string, type: string, state: string}  $workItem
     * @param  array{created: ?string, activated: ?string, closed: ?string}  $dates  fechas ISO 8601
     * @param  array  $pullRequest  formato de AzureDevOpsService::getPullRequest()
     * @param  array<int, array{commitId: string, message: string, author: string, date: string}>  $commits
     * @param  string[]  $files
     * @param  string[]  $warnings
     */
    public function __construct(
        public readonly array $workItem,
        public readonly array $dates,
        public readonly array $pullRequest,
        public readonly array $commits,
        public readonly array $files,
        public readonly array $warnings = [],
    ) {}

    /**
     * Sección de fechas: la arma la app (no la IA) para que no haya fechas inventadas.
     */
    public function datesMarkdown(string $timezone): string
    {
        $date = fn (?string $value) => $value ? CarbonImmutable::parse($value)->setTimezone($timezone)->format('d/m/Y') : '—';
        $pr = $this->pullRequest;

        $lines = [
            '**Fechas**',
            '',
            "- Work item: creado {$date($this->dates['created'])} · inicio {$date($this->dates['activated'])} · cerrado {$date($this->dates['closed'])}",
            "- PR: creado {$date($pr['creationDate'] ?? null)} · completado {$date($pr['closedDate'] ?? null)} "
                ."(`{$pr['sourceBranch']}` → `{$pr['targetBranch']}`, repo `{$pr['repository']}`)",
        ];

        if ($this->commits !== []) {
            $first = $date($this->commits[0]['date']);
            $last = $date($this->commits[count($this->commits) - 1]['date']);
            $lines[] = '- Commits: '.count($this->commits).($first === $last ? ", el {$first}" : ", del {$first} al {$last}");
        } else {
            $lines[] = '- Commits: no se encontraron commits propios en el PR';
        }

        return implode("\n", $lines);
    }

    public function filesMarkdown(int $max = 15): ?string
    {
        if ($this->files === []) {
            return null;
        }

        $shown = collect($this->files)->take($max)->map(fn ($f) => '`'.ltrim($f, '/').'`')->implode(', ');
        $more = count($this->files) > $max ? ' y '.(count($this->files) - $max).' más' : '';

        return "**Archivos modificados:** {$shown}{$more}";
    }

    public function toArray(): array
    {
        return [
            'kind' => 'closure',
            'work_item' => $this->workItem,
            'dates' => $this->dates,
            // Lista de un elemento: mismo formato que el contexto del comentario diario
            'pull_requests' => [$this->pullRequest],
            'branches' => [],
            'commits' => $this->commits,
            'files' => $this->files,
            'warnings' => $this->warnings,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['work_item'],
            $data['dates'] ?? ['created' => null, 'activated' => null, 'closed' => null],
            $data['pull_requests'][0] ?? [],
            $data['commits'] ?? [],
            $data['files'] ?? [],
            $data['warnings'] ?? [],
        );
    }
}
