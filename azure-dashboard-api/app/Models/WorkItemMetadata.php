<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItemMetadata extends Model
{
    protected $table = 'work_item_metadata';

    protected $fillable = [
        'azure_work_item_id',
        'work_item_type',
        'estimated_delivery_date',
        'possible_pap_date',
        'ready_to_deploy',
    ];

    protected function casts(): array
    {
        return [
            'estimated_delivery_date' => 'datetime',
            'possible_pap_date' => 'datetime',
            'ready_to_deploy' => 'boolean',
        ];
    }

    public function checklistStatuses(): HasMany
    {
        return $this->hasMany(ChecklistStatus::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AgentApproval::class);
    }

    public static function forWorkItem(int|string $workItemId, string $type = ''): self
    {
        $metadata = static::firstOrCreate(
            ['azure_work_item_id' => (string) $workItemId],
            ['work_item_type' => $type],
        );

        if ($type !== '' && $metadata->work_item_type !== $type) {
            $metadata->update(['work_item_type' => $type]);
        }

        return $metadata;
    }
}
