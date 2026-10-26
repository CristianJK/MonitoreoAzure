<?php

namespace App\Http\Resources;

use App\Models\AgentApproval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentApproval
 */
class AgentApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = $this->payload ?? [];

        return [
            'id' => $this->id,
            'work_item_id' => (int) $this->workItemMetadata->azure_work_item_id,
            'work_item' => $payload['work_item'] ?? null,
            'action_code' => $this->action_code,
            'has_commits' => $this->action_code === 'daily_comment',
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'resolved_by' => $this->resolved_by,
            'date' => $payload['date'] ?? null,
            'proposal' => $payload['proposal'] ?? null,
            'final_text' => $payload['final_text'] ?? null,
            'generator' => $payload['generator'] ?? null,
            'fallback_reason' => $payload['fallback_reason'] ?? null,
            'context' => $payload['context'] ?? null,
            'created_at' => $this->created_at,
            'resolved_at' => $this->resolved_at,
        ];
    }
}
