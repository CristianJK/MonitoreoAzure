<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistStatus extends Model
{
    public const STATUSES = ['pending', 'in_progress', 'done', 'failed', 'skipped'];

    protected $table = 'checklist_status';

    protected $fillable = [
        'work_item_metadata_id',
        'checklist_definition_id',
        'status',
        'value',
        'notes',
        'checked_by',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ChecklistDefinition::class, 'checklist_definition_id')->withTrashed();
    }

    public function workItemMetadata(): BelongsTo
    {
        return $this->belongsTo(WorkItemMetadata::class);
    }
}
