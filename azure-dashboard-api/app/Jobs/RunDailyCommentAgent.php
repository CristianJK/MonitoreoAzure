<?php

namespace App\Jobs;

use App\Services\Agents\AgentCommentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Genera la propuesta de comentario diario de un work item (no publica).
 */
class RunDailyCommentAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $workItemId,
        public string $trigger,
        public ?string $batchId = null,
    ) {}

    public function handle(AgentCommentService $agent): void
    {
        $agent->propose($this->workItemId, $this->trigger, $this->batchId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(AgentCommentService::class)->recordRun($this->workItemId, $this->trigger, $this->batchId, [
            'status' => 'error',
            'error' => $exception?->getMessage(),
        ]);
    }
}
