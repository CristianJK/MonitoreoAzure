<?php

namespace Database\Seeders;

use App\Models\ChecklistDefinition;
use Illuminate\Database\Seeder;

/**
 * Items de alistamiento iniciales (los que estaban fijos en la vista del WI).
 * Los estados corresponden al proceso Scrum del proyecto (Task: To Do → In Progress → Done).
 */
class ChecklistDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['code' => 'solicitud_rama', 'label' => 'Solicitud de Rama', 'description' => 'Número de la WO con la que se solicitó la rama.', 'data_type' => 'text', 'work_item_state' => 'To Do', 'blocking' => true],
            ['code' => 'pull_request', 'label' => 'Pull Request Asociado', 'data_type' => 'url', 'work_item_state' => 'In Progress', 'blocking' => true, 'source' => 'azure_pr'],
            ['code' => 'pruebas_unitarias', 'label' => 'Pruebas Unitarias', 'data_type' => 'boolean', 'work_item_state' => 'In Progress', 'blocking' => false],
            ['code' => 'manual_tecnico', 'label' => 'Manual Técnico', 'data_type' => 'sharepoint_link', 'work_item_state' => 'In Progress', 'blocking' => true],
            ['code' => 'minutograma', 'label' => 'Minutograma', 'data_type' => 'sharepoint_link', 'work_item_state' => 'In Progress', 'blocking' => true],
            ['code' => 'validacion_estandares', 'label' => 'Validación de Estándares', 'data_type' => 'boolean', 'work_item_state' => 'In Progress', 'blocking' => false],
            ['code' => 'estado_pipeline', 'label' => 'Estado del Pipeline', 'data_type' => 'text', 'work_item_state' => 'Done', 'blocking' => false, 'source' => 'azure_pipeline'],
        ];

        foreach ($definitions as $order => $definition) {
            ChecklistDefinition::updateOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'source' => $definition['source'] ?? 'manual', 'sort_order' => $order],
            );
        }
    }
}
