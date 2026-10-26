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

        return $text;
    }

    protected function prompt(DailyProgress $progress): string
    {
        $data = $progress->toArray();
        unset($data['warnings']);

        return 'Redacta en español el comentario de avance del día para este work item de Azure DevOps. '
            .'Usa markdown, máximo 8 líneas: avance del día, archivos o áreas tocadas y próximos pasos solo si se deducen de los mensajes. '
            .'No inventes información que no esté en el JSON. No agregues saludo ni encabezado. Responde solo con el comentario.'
            ."\n\n```json\n".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```";
    }
}
