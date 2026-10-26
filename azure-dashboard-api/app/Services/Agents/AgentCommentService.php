<?php

namespace App\Services\Agents;

use App\Jobs\RunDailyCommentAgent;
use App\Models\AgentApproval;
use App\Models\AgentRun;
use App\Models\WorkItemMetadata;
use App\Services\AzureDevOpsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Flujo del comentario diario: propuesta → revisión del usuario → publicación.
 * Nada se publica en Azure DevOps sin pasar por approve().
 */
class AgentCommentService
{
    public function __construct(
        protected AzureDevOpsService $azure,
        protected DailyProgressCollector $collector,
        protected CommentGenerator $generator,
        protected TemplateCommentGenerator $fallback,
    ) {}

    /**
     * Recolecta el avance del día y deja una propuesta pendiente de revisión.
     * Si no hay commits hoy, la propuesta queda sin texto (comentario manual).
     */
    public function propose(int $workItemId, string $trigger, ?string $batchId = null): AgentApproval|array
    {
        $today = $this->today();

        $progress = $this->collector->collect($workItemId);
        if (is_array($progress)) {
            $this->recordRun($workItemId, $trigger, $batchId, ['status' => 'error', 'error' => $progress['error']]);

            return $progress;
        }

        $metadata = WorkItemMetadata::forWorkItem($workItemId, $progress->workItem['type']);

        $metadata->approvals()
            ->where('status', AgentApproval::PENDING)
            ->update(['status' => AgentApproval::REPLACED, 'resolved_at' => now()]);

        $payload = [
            'date' => $today->toDateString(),
            'work_item' => $progress->workItem,
            'proposal' => null,
            'generator' => null,
            'fallback_reason' => null,
            'context' => $progress->toArray(),
        ];

        if ($progress->hasCommits()) {
            $payload = [...$payload, ...$this->generateBody($progress)];
        }

        $approval = $metadata->approvals()->create([
            'action_code' => $progress->hasCommits()
                ? AgentApproval::ACTION_DAILY_COMMENT
                : AgentApproval::ACTION_DAILY_COMMENT_MANUAL,
            'status' => AgentApproval::PENDING,
            'requested_by' => $trigger,
            'payload' => $payload,
        ]);

        $this->recordRun($workItemId, $trigger, $batchId, [
            'status' => $progress->hasCommits() ? 'proposal' : 'manual_required',
            'agent_approval_id' => $approval->id,
            'summary' => [
                'title' => $progress->workItem['title'],
                'pull_requests' => count($progress->pullRequests),
                'branches' => count($progress->branches),
                'commits' => count($progress->commits),
                'generator' => $payload['generator'],
            ],
        ]);

        return $approval;
    }

    public function regenerate(AgentApproval $approval): AgentApproval|array
    {
        if ($approval->status !== AgentApproval::PENDING || $approval->action_code !== AgentApproval::ACTION_DAILY_COMMENT) {
            return ['error' => 'Solo se pueden regenerar propuestas pendientes con commits', 'status' => 409];
        }

        $progress = DailyProgress::fromArray($approval->payload['context']);
        $approval->update(['payload' => [...$approval->payload, ...$this->generateBody($progress)]]);

        return $approval;
    }

    /**
     * Publica en Azure DevOps el texto revisado por el usuario.
     */
    public function approve(AgentApproval $approval, string $text, ?string $user, bool $force = false): AgentApproval|array
    {
        if ($approval->status !== AgentApproval::PENDING) {
            return ['error' => "La propuesta ya fue resuelta ({$approval->status})", 'status' => 409];
        }

        $workItemId = (int) $approval->workItemMetadata->azure_work_item_id;

        if (! $force && $this->publishedToday($workItemId)) {
            return ['error' => 'Ya se publicó hoy un comentario del agente en este Work Item', 'status' => 409, 'code' => 'already_published'];
        }

        $comment = $this->azure->addWorkItemComment($workItemId, $text);
        if (isset($comment['error'])) {
            return $comment;
        }

        $approval->update([
            'status' => AgentApproval::PUBLISHED,
            'resolved_by' => $user,
            'resolved_at' => now(),
            'payload' => [...$approval->payload, 'final_text' => $text],
            'result' => $comment,
        ]);

        AgentRun::where('agent_approval_id', $approval->id)->update(['azure_comment_id' => $comment['id']]);

        return $approval;
    }

    public function reject(AgentApproval $approval, ?string $user): AgentApproval|array
    {
        if ($approval->status !== AgentApproval::PENDING) {
            return ['error' => "La propuesta ya fue resuelta ({$approval->status})", 'status' => 409];
        }

        $approval->update([
            'status' => AgentApproval::REJECTED,
            'resolved_by' => $user,
            'resolved_at' => now(),
        ]);

        return $approval;
    }

    /**
     * Encola un job por cada work item abierto del usuario.
     *
     * @return array{batch_id: string, count: int}|array{error: string}
     */
    public function startBatch(?string $assignedTo, string $trigger): array
    {
        $this->expireStale();

        $ids = $this->azure->getOpenWorkItemIds($assignedTo);
        if (isset($ids['error'])) {
            return $ids;
        }

        $batchId = (string) Str::uuid();

        foreach ($ids as $id) {
            $this->recordRun($id, $trigger, $batchId, ['status' => 'queued', 'agent_approval_id' => null, 'summary' => null]);
            RunDailyCommentAgent::dispatch($id, $trigger, $batchId);
        }

        return ['batch_id' => $batchId, 'count' => count($ids)];
    }

    /**
     * Las propuestas de días anteriores no se publican como "resumen de hoy".
     */
    public function expireStale(): int
    {
        return AgentApproval::where('status', AgentApproval::PENDING)
            ->where('created_at', '<', $this->today()->utc())
            ->update(['status' => AgentApproval::EXPIRED, 'resolved_at' => now()]);
    }

    public function publishedToday(int $workItemId): bool
    {
        return AgentApproval::where('status', AgentApproval::PUBLISHED)
            ->where('resolved_at', '>=', $this->today()->utc())
            ->whereHas('workItemMetadata', fn ($q) => $q->where('azure_work_item_id', (string) $workItemId))
            ->exists();
    }

    public function recordRun(int $workItemId, string $trigger, ?string $batchId, array $values): void
    {
        AgentRun::updateOrCreate(
            [
                'azure_work_item_id' => (string) $workItemId,
                'run_date' => $this->today()->toDateString(),
                'trigger' => $trigger,
            ],
            ['batch_id' => $batchId, 'error' => null, ...$values],
        );
    }

    /**
     * @return array{proposal: string, generator: string, fallback_reason: ?string}
     */
    protected function generateBody(DailyProgress $progress): array
    {
        $fallbackReason = null;

        try {
            $body = $this->generator->generate($progress);
            $generator = $this->generator->name();
        } catch (\Throwable $e) {
            Log::warning('Fallo el generador de comentarios, se usa la plantilla', [
                'work_item' => $progress->workItem['id'],
                'error' => $e->getMessage(),
            ]);
            $body = $this->fallback->generate($progress);
            $generator = $this->fallback->name();
            $fallbackReason = $e->getMessage();
        }

        $date = CarbonImmutable::parse($progress->date)->format('d/m/Y');

        return [
            'proposal' => "**🤖 Resumen automático del día ({$date})**\n\n".trim($body),
            'generator' => $generator,
            'fallback_reason' => $fallbackReason,
        ];
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('agents.timezone'))->startOfDay();
    }
}
