<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentApproval extends Model
{
    public const PENDING = 'Pendiente';

    public const PUBLISHED = 'Publicado';

    public const REJECTED = 'Rechazado';

    public const REPLACED = 'Reemplazado';

    public const EXPIRED = 'Vencido';

    public const ACTION_DAILY_COMMENT = 'daily_comment';

    public const ACTION_DAILY_COMMENT_MANUAL = 'daily_comment_manual';

    protected $fillable = [
        'work_item_metadata_id',
        'action_code',
        'status',
        'requested_by',
        'resolved_by',
        'payload',
        'result',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function workItemMetadata(): BelongsTo
    {
        return $this->belongsTo(WorkItemMetadata::class);
    }
}
