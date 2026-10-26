<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo de la Direct Line API 3.0 para conversar con un agente de Copilot Studio.
 */
class DirectLineClient
{
    protected const USER_ID = 'monitoreo-azure';

    public function __construct(
        protected string $baseUrl,
        protected string $secret,
        protected int $timeout = 30,
    ) {}

    /**
     * Abre una conversación nueva, envía el mensaje y espera la respuesta del agente.
     */
    public function ask(string $message): string
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/'))->acceptJson()->timeout(15);

        $conversation = $http->withToken($this->secret)->post('/conversations')->throw()->json();
        $conversationId = $conversation['conversationId'] ?? throw new RuntimeException('Direct Line no devolvió conversationId');
        $token = $conversation['token'] ?? $this->secret;

        $sent = $http->withToken($token)
            ->post("/conversations/{$conversationId}/activities", [
                'type' => 'message',
                'from' => ['id' => self::USER_ID],
                'locale' => 'es-CO',
                'text' => $message,
            ])
            ->throw()
            ->json();
        $activityId = $sent['id'] ?? null;

        $deadline = microtime(true) + $this->timeout;
        $watermark = null;
        $replies = [];

        while (microtime(true) < $deadline) {
            usleep(1_000_000);

            $response = $http->withToken($token)
                ->get("/conversations/{$conversationId}/activities", array_filter(['watermark' => $watermark]))
                ->throw()
                ->json();
            $watermark = $response['watermark'] ?? $watermark;

            foreach ($response['activities'] ?? [] as $activity) {
                $isBotMessage = ($activity['type'] ?? '') === 'message'
                    && ($activity['from']['id'] ?? '') !== self::USER_ID
                    && trim($activity['text'] ?? '') !== '';

                if ($isBotMessage && (! $activityId || ($activity['replyToId'] ?? $activityId) === $activityId)) {
                    $replies[] = trim($activity['text']);
                }
            }

            // El agente puede responder en varios mensajes: se espera a que deje de escribir
            if ($replies !== [] && empty($response['activities'])) {
                return implode("\n\n", $replies);
            }
        }

        if ($replies !== []) {
            return implode("\n\n", $replies);
        }

        throw new RuntimeException("Copilot Studio no respondió en {$this->timeout} segundos");
    }
}
