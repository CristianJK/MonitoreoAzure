<?php

namespace App\Services\Agents;

/**
 * Generador sin IA: arma el comentario con una plantilla a partir de los datos.
 */
class TemplateCommentGenerator implements CommentGenerator
{
    public function name(): string
    {
        return 'template';
    }

    public function generate(DailyProgress $progress): string
    {
        $lines = [];

        foreach ($progress->pullRequests as $pr) {
            $lines[] = "**Pull Request:** [!{$pr['id']} {$pr['title']}]({$pr['url']}) "
                ."(`{$pr['sourceBranch']}` → `{$pr['targetBranch']}`, {$pr['status']})";
        }

        if ($progress->pullRequests === [] && $progress->branches !== []) {
            $names = array_map(fn ($b) => "`{$b['name']}`", $progress->branches);
            $lines[] = '**Rama:** '.implode(', ', $names);
        }

        $lines[] = '';
        $lines[] = '**Avances del día ('.count($progress->commits).' commits):**';
        $lines[] = '';

        foreach ($progress->commits as $commit) {
            $message = strtok($commit['message'], "\n") ?: '(sin mensaje)';
            $lines[] = "- {$commit['time']} — {$message} (`".substr($commit['commitId'], 0, 7).'`)';
        }

        $files = collect($progress->commits)->flatMap(fn ($c) => $c['files'])->unique()->values();
        if ($files->isNotEmpty()) {
            $shown = $files->take(10)->map(fn ($f) => '`'.ltrim($f, '/').'`')->implode(', ');
            $more = $files->count() > 10 ? ' y '.($files->count() - 10).' más' : '';
            $lines[] = '';
            $lines[] = "**Archivos modificados:** {$shown}{$more}";
        }

        return implode("\n", $lines);
    }
}
