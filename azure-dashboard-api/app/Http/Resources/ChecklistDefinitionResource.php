<?php

namespace App\Http\Resources;

use App\Models\ChecklistDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChecklistDefinition
 */
class ChecklistDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'description' => $this->description,
            'data_type' => $this->data_type->value,
            'data_type_label' => $this->data_type->label(),
            'options' => $this->options ?? [],
            'work_item_state' => $this->work_item_state,
            'blocking' => $this->blocking,
            'is_required' => $this->is_required,
            'applies_to_type' => $this->applies_to_type,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'sort_order' => $this->sort_order,
            'values_count' => $this->whenCounted('statuses'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
