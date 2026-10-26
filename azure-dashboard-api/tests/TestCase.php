<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'azure.org' => 'org-test',
            'azure.project' => 'Proyecto Test',
            'azure.pat' => 'pat-test',
            'agents.comment_generator' => 'template',
            'agents.timezone' => 'America/Bogota',
        ]);

        // Ningún test debe llamar a Azure DevOps real
        Http::preventStrayRequests();
    }

    /**
     * Respuesta simulada de la lista de tipos de Work Item con sus estados.
     */
    protected function fakeWorkItemTypes(): array
    {
        return [
            '*/_apis/wit/workitemtypes/Task/states*' => Http::response(['value' => [
                ['name' => 'To Do', 'color' => 'b2b2b2', 'category' => 'Proposed'],
                ['name' => 'In Progress', 'color' => '007acc', 'category' => 'InProgress'],
                ['name' => 'Done', 'color' => '339933', 'category' => 'Completed'],
                ['name' => 'Removed', 'color' => 'ffffff', 'category' => 'Removed'],
            ]]),
            '*/_apis/wit/workitemtypes/Bug/states*' => Http::response(['value' => [
                ['name' => 'New', 'color' => 'b2b2b2', 'category' => 'Proposed'],
                ['name' => 'Committed', 'color' => '007acc', 'category' => 'InProgress'],
                ['name' => 'Done', 'color' => '339933', 'category' => 'Completed'],
            ]]),
            '*/_apis/wit/workitemtypes?*' => Http::response(['value' => [
                ['name' => 'Task'],
                ['name' => 'Bug'],
                ['name' => 'Old', 'isDisabled' => true],
            ]]),
        ];
    }

    protected function workItemResponse(int $id, array $fields = [], array $relations = []): array
    {
        return [
            'id' => $id,
            'fields' => [
                'System.Title' => 'Tarea de prueba',
                'System.WorkItemType' => 'Task',
                'System.State' => 'In Progress',
                ...$fields,
            ],
            'relations' => $relations,
        ];
    }
}
