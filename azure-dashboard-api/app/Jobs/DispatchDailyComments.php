<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Agents\AgentCommentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ejecución programada: deja propuestas pendientes de revisión para los WIs
 * abiertos de cada usuario que la activó, usando su propio PAT.
 */
class DispatchDailyComments implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $trigger = 'scheduled',
    ) {}

    public function handle(): void
    {
        $users = User::where('agent_schedule_enabled', true)->whereNotNull('azure_pat')->get();

        foreach ($users as $user) {
            $result = $user->actAs(fn () => app(AgentCommentService::class)->startBatch($user, $this->trigger));

            if (isset($result['error'])) {
                Log::error('No se pudo iniciar el lote de comentarios diarios', ['user' => $user->email, ...$result]);
            }
        }
    }
}
