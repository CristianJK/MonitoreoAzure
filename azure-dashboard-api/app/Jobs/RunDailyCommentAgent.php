<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Agents\AgentCommentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Genera la propuesta de comentario diario de un work item (no publica).
 * Lee Azure con el PAT del dueño; la propuesta queda en su bandeja.
 */
class RunDailyCommentAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $workItemId,
        public string $trigger,
        public ?string $batchId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $owner = User::find($this->userId);

        if (! $owner?->azure_pat) {
            $this->failed(new \RuntimeException('El usuario no tiene un PAT vigente: debe iniciar sesión de nuevo'));

            return;
        }

        $owner->actAs(fn () => app(AgentCommentService::class)
            ->propose($this->workItemId, $this->trigger, $this->batchId, $owner));
    }

    public function failed(?\Throwable $exception): void
    {
        app(AgentCommentService::class)->recordRun($this->workItemId, $this->trigger, $this->batchId, [
            'user_id' => $this->userId,
            'status' => 'error',
            'error' => $exception?->getMessage(),
        ]);
    }
}
