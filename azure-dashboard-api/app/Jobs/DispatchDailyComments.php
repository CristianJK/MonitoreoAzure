<?php

namespace App\Jobs;

use App\Services\Agents\AgentCommentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ejecución programada: deja propuestas pendientes de revisión para los WIs abiertos.
 */
class DispatchDailyComments implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $assignedTo = '@Me',
        public string $trigger = 'scheduled',
    ) {}

    public function handle(AgentCommentService $agent): void
    {
        $result = $agent->startBatch($this->assignedTo, $this->trigger);

        if (isset($result['error'])) {
            Log::error('No se pudo iniciar el lote de comentarios diarios', $result);
        }
    }
}
