<?php

namespace App\Services\Agents;

use RuntimeException;

/**
 * Pide a un agente de Copilot Studio (vía Direct Line) que redacte el comentario.
 * El agente solo recibe los datos ya recolectados; no necesita acceso a Azure DevOps.
 */
class CopilotStudioCommentGenerator implements CommentGenerator
{
    public function __construct(protected ?DirectLineClient $client) {}

    public function name(): string
    {
        return 'copilot_studio';
    }

    public function generate(DailyProgress $progress): string
    {
        if (! $this->client) {
            throw new RuntimeException('Copilot Studio no está configurado (falta COPILOT_DIRECTLINE_SECRET)');
        }

        $text = $this->client->ask($this->prompt($progress));

        if (mb_strlen($text) < 10) {
            throw new RuntimeException('Copilot Studio devolvió una respuesta vacía');
        }

        return static::stripHeader($text);
    }

    protected function prompt(DailyProgress $progress): string
    {
        $data = $progress->toArray();
        unset($data['warnings']);
        $count = count($data['commits'] ?? []);

        return "Redacta en español el comentario de avance del día para este work item de Azure DevOps a partir del JSON adjunto.\n"
            ."Reglas obligatorias:\n"
            ."- El JSON trae {$count} commits: el resumen debe cubrirlos TODOS, no solo el primero ni el último.\n"
            ."- Agrupa los commits relacionados en un mismo punto; usa una lista markdown de máximo 8 viñetas.\n"
            ."- Menciona archivos o áreas tocadas y próximos pasos solo si se deducen de los mensajes.\n"
            ."- No agregues título, encabezado, fecha, número de PR, rama, saludo ni despedida: empieza directamente con la primera viñeta.\n"
            ."- No inventes información que no esté en el JSON. Responde solo con el comentario.\n"
            ."\n```json\n".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```";
    }

    /**
     * Quita un título que el agente agregue por su cuenta (p. ej. "**Avance del 2026-09-16** ..."),
     * ya que la app pone su propio encabezado.
     */
    public static function stripHeader(string $text): string
    {
        $lines = preg_split('/\R/', trim($text));
        $first = trim($lines[0] ?? '');

        $isHeader = preg_match('/^(#{1,6}\s|\*\*[^*]+\*\*)/u', $first)
            && preg_match('/(avance|resumen|comentario|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{2,4})/iu', $first)
            && ! preg_match('/^\s*[-*]\s/u', $first);

        if ($isHeader && count($lines) > 1) {
            array_shift($lines);
        }

        return trim(implode("\n", $lines));
    }
}
