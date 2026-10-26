<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    protected $fillable = [
        'azure_work_item_id',
        'run_date',
        'trigger',
        'batch_id',
        'status',
        'summary',
        'agent_approval_id',
        'azure_comment_id',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'run_date' => 'date:Y-m-d',
            'summary' => 'array',
        ];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(AgentApproval::class, 'agent_approval_id');
    }
}
