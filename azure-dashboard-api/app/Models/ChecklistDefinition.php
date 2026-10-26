<?php

namespace App\Models;

use App\Enums\ChecklistDataType;
use App\Enums\ChecklistSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistDefinition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'label',
        'description',
        'data_type',
        'options',
        'work_item_state',
        'blocking',
        'is_required',
        'applies_to_type',
        'source',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'data_type' => ChecklistDataType::class,
            'source' => ChecklistSource::class,
            'options' => 'array',
            'blocking' => 'boolean',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ChecklistStatus::class);
    }

    public function scopeForType(Builder $query, ?string $type): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('applies_to_type')->orWhere('applies_to_type', $type));
    }
}
