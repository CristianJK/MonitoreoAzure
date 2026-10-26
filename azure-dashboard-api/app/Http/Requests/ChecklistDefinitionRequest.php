<?php

namespace App\Http\Requests;

use App\Enums\ChecklistDataType;
use App\Enums\ChecklistSource;
use App\Services\AzureDevOpsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ChecklistDefinitionRequest extends FormRequest
{
    public function rules(): array
    {
        $definition = $this->route('checklist_definition');

        return [
            'label' => 'required|string|max:150',
            'code' => [
                'nullable', 'string', 'max:100', 'alpha_dash',
                Rule::unique('checklist_definitions', 'code')->ignore($definition?->id),
            ],
            'description' => 'nullable|string|max:2000',
            'data_type' => ['required', Rule::enum(ChecklistDataType::class)],
            'options' => 'exclude_unless:data_type,select|required|array|min:1',
            'options.*' => 'required|string|distinct|max:150',
            'work_item_state' => 'required|string|max:100',
            'blocking' => 'boolean',
            'is_required' => 'boolean',
            'applies_to_type' => 'nullable|string|max:100',
            'source' => ['nullable', Rule::enum(ChecklistSource::class)],
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'El nombre es obligatorio.',
            'data_type.required' => 'El tipo de dato es obligatorio.',
            'data_type.enum' => 'El tipo de dato no es válido.',
            'options.required' => 'Agrega al menos una opción para el tipo "Lista de opciones".',
            'options.min' => 'Agrega al menos una opción para el tipo "Lista de opciones".',
            'options.*.distinct' => 'Las opciones no se pueden repetir.',
            'work_item_state.required' => 'El estado es obligatorio.',
            'code.unique' => 'Ya existe un item con ese código.',
            'code.alpha_dash' => 'El código solo admite letras, números, guiones y guion bajo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'label' => 'nombre',
            'data_type' => 'tipo de dato',
            'options' => 'opciones',
            'work_item_state' => 'estado',
            'applies_to_type' => 'tipo de Work Item',
        ];
    }

    /**
     * El estado debe existir en Azure DevOps para el tipo elegido
     * (o en alguno de los tipos si aplica a todos).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['work_item_state', 'applies_to_type'])) {
                    return;
                }

                $types = app(AzureDevOpsService::class)->getWorkItemTypes();
                if (isset($types['error'])) {
                    $validator->errors()->add('work_item_state', 'No se pudieron consultar los estados en Azure DevOps.');

                    return;
                }

                $type = $this->input('applies_to_type');
                $candidates = collect($types);

                if ($type) {
                    $candidates = $candidates->filter(fn ($t) => $t['name'] === $type);
                    if ($candidates->isEmpty()) {
                        $validator->errors()->add('applies_to_type', "El tipo '{$type}' no existe en el proyecto.");

                        return;
                    }
                }

                $states = $candidates->flatMap(fn ($t) => array_column($t['states'], 'name'))->unique();
                if (! $states->contains($this->input('work_item_state'))) {
                    $validator->errors()->add('work_item_state', 'El estado no existe para el tipo de Work Item seleccionado.');
                }
            },
        ];
    }
}
