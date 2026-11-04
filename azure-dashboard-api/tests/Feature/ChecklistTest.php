<?php

namespace Tests\Feature;

use App\Models\ChecklistDefinition;
use App\Models\ChecklistStatus;
use App\Models\WorkItemMetadata;
use Database\Seeders\ChecklistDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAzureUser();
    }

    protected function fakeAzure(array $relations = []): void
    {
        Http::fake([
            ...$this->fakeWorkItemTypes(),
            '*/_apis/wit/workitems/50*' => Http::response($this->workItemResponse(50, [], $relations)),
            '*/pullrequests/700*' => Http::response([
                'pullRequestId' => 700, 'title' => 'Login', 'status' => 'active',
                'sourceRefName' => 'refs/heads/feature/login', 'targetRefName' => 'refs/heads/develop',
                'repository' => ['name' => 'repo-web'],
            ]),
            '*/_apis/build/builds*' => Http::response(['value' => [[
                'id' => 1, 'buildNumber' => '20261026.1', 'status' => 'completed', 'result' => 'failed',
                'definition' => ['name' => 'CI'], '_links' => ['web' => ['href' => 'https://build/1']],
            ]]]),
        ]);
    }

    public function test_crud_of_definitions(): void
    {
        Http::fake($this->fakeWorkItemTypes());

        $id = $this->postJson('/api/checklist-definitions', [
            'label' => 'Manual Técnico',
            'data_type' => 'sharepoint_link',
            'work_item_state' => 'In Progress',
            'applies_to_type' => 'Task',
            'blocking' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'manual_tecnico')
            ->assertJsonPath('data.is_required', true)
            ->json('data.id');

        $this->putJson("/api/checklist-definitions/{$id}", [
            'label' => 'Manual Técnico v2',
            'data_type' => 'select',
            'options' => ['A', 'B'],
            'work_item_state' => 'Done',
        ])->assertOk()->assertJsonPath('data.options', ['A', 'B']);

        $this->getJson('/api/checklist-definitions?state=Done')->assertJsonCount(1, 'data');

        $this->deleteJson("/api/checklist-definitions/{$id}")->assertOk();
        $this->assertSoftDeleted('checklist_definitions', ['id' => $id]);
    }

    public function test_state_must_exist_for_type(): void
    {
        Http::fake($this->fakeWorkItemTypes());

        $this->postJson('/api/checklist-definitions', [
            'label' => 'X', 'data_type' => 'text', 'work_item_state' => 'Committed', 'applies_to_type' => 'Task',
        ])->assertJsonValidationErrors('work_item_state');

        // Sin tipo, vale cualquier estado de algún tipo
        $this->postJson('/api/checklist-definitions', [
            'label' => 'X', 'data_type' => 'text', 'work_item_state' => 'Committed',
        ])->assertCreated();
    }

    public function test_select_requires_options(): void
    {
        Http::fake($this->fakeWorkItemTypes());

        $this->postJson('/api/checklist-definitions', [
            'label' => 'X', 'data_type' => 'select', 'work_item_state' => 'Done',
        ])->assertJsonValidationErrors('options');
    }

    public function test_empty_options_are_ignored_for_non_select_types(): void
    {
        Http::fake($this->fakeWorkItemTypes());

        $this->postJson('/api/checklist-definitions', [
            'label' => 'X', 'data_type' => 'text', 'work_item_state' => 'Done', 'options' => [],
        ])->assertCreated()->assertJsonPath('data.options', []);

        $this->postJson('/api/checklist-definitions', [
            'label' => 'Y', 'data_type' => 'select', 'work_item_state' => 'Done', 'options' => [],
        ])->assertJsonValidationErrors('options');
    }

    public function test_cannot_change_data_type_with_values(): void
    {
        Http::fake($this->fakeWorkItemTypes());
        $definition = ChecklistDefinition::create(['code' => 'x', 'label' => 'X', 'data_type' => 'text', 'work_item_state' => 'Done']);
        $metadata = WorkItemMetadata::forWorkItem(1, 'Task');
        ChecklistStatus::create(['work_item_metadata_id' => $metadata->id, 'checklist_definition_id' => $definition->id, 'value' => 'abc']);

        $this->putJson("/api/checklist-definitions/{$definition->id}", [
            'label' => 'X', 'data_type' => 'number', 'work_item_state' => 'Done',
        ])->assertJsonValidationErrors('data_type');
    }

    public function test_work_item_checklist_groups_and_automatic_items(): void
    {
        $this->seed(ChecklistDefinitionSeeder::class);
        $this->fakeAzure([
            ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/p/repo/700'],
        ]);

        $response = $this->getJson('/api/work-items/50/checklist')->assertOk();

        $groups = collect($response->json('data.groups'));
        $this->assertSame(['To Do', 'In Progress', 'Done'], $groups->pluck('state')->all());
        $this->assertTrue($groups->firstWhere('state', 'In Progress')['is_current']);

        $items = $groups->flatMap(fn ($g) => $g['items'])->keyBy('code');
        $this->assertSame('done', $items['pull_request']['status']);
        $this->assertSame('PR !700 (active)', $items['pull_request']['value']);
        $this->assertSame('failed', $items['estado_pipeline']['status']);
        $this->assertSame('pending', $items['manual_tecnico']['status']);

        $this->assertDatabaseHas('checklist_status', ['value' => 'PR !700 (active)', 'checked_by' => 'system']);
    }

    public function test_saves_value_validated_by_data_type(): void
    {
        $this->seed(ChecklistDefinitionSeeder::class);
        $this->fakeAzure();
        $manual = ChecklistDefinition::where('code', 'manual_tecnico')->first();
        $tests = ChecklistDefinition::where('code', 'pruebas_unitarias')->first();
        $pr = ChecklistDefinition::where('code', 'pull_request')->first();

        $this->putJson("/api/work-items/50/checklist/{$manual->id}", ['value' => 'https://google.com/doc'])
            ->assertJsonValidationErrors('value');

        $this->putJson("/api/work-items/50/checklist/{$manual->id}", ['value' => 'https://claro.sharepoint.com/sites/a/doc.docx'])
            ->assertOk()
            ->assertJsonPath('data.item.status', 'done')
            ->assertJsonPath('data.item.checked_by', 'Ana');

        $this->putJson("/api/work-items/50/checklist/{$tests->id}", ['value' => false, 'status' => 'skipped'])
            ->assertOk()
            ->assertJsonPath('data.item.value', '0')
            ->assertJsonPath('data.item.status', 'skipped');

        $this->putJson("/api/work-items/50/checklist/{$pr->id}", ['value' => 'x'])->assertUnprocessable();
    }

    public function test_ready_to_deploy_when_all_blocking_items_are_done(): void
    {
        $this->fakeAzure();
        $blocking = ChecklistDefinition::create(['code' => 'a', 'label' => 'A', 'data_type' => 'boolean', 'work_item_state' => 'Done', 'blocking' => true]);
        ChecklistDefinition::create(['code' => 'b', 'label' => 'B', 'data_type' => 'text', 'work_item_state' => 'Done']);

        $this->putJson("/api/work-items/50/checklist/{$blocking->id}", ['value' => true])
            ->assertJsonPath('data.summary', ['total' => 2, 'done' => 1, 'blocking_pending' => 0, 'ready_to_deploy' => true]);

        $this->getJson('/api/work-items/checklist-summary?ids[]=50&ids[]=51')
            ->assertJsonPath('data.50.ready_to_deploy', true)
            ->assertJsonMissingPath('data.51');
    }

    public function test_sharepoint_link_is_added_to_the_work_item_and_replaced_on_change(): void
    {
        $definition = ChecklistDefinition::create(['code' => 'manual', 'label' => 'Manual Técnico', 'data_type' => 'sharepoint_link', 'work_item_state' => 'Done']);
        $old = 'https://claro.sharepoint.com/sites/a/v1.docx';
        Http::fake([
            '*/_apis/wit/workitems/50*' => Http::response([
                ...$this->workItemResponse(50, [], [['rel' => 'Hyperlink', 'url' => 'https://otro'], ['rel' => 'Hyperlink', 'url' => $old]]),
                'rev' => 7,
            ]),
        ]);

        $this->putJson("/api/work-items/50/checklist/{$definition->id}", ['value' => $old])
            ->assertOk()
            ->assertJsonMissingPath('data.warning');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && ($r->data()[0]['value'] ?? null) === ['rel' => 'Hyperlink', 'url' => $old, 'attributes' => ['comment' => 'Manual Técnico']]);

        $this->putJson("/api/work-items/50/checklist/{$definition->id}", ['value' => 'https://claro.sharepoint.com/sites/a/v2.docx'])->assertOk();

        // Quita el anterior por su índice, validando la revisión
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === [['op' => 'test', 'path' => '/rev', 'value' => 7], ['op' => 'remove', 'path' => '/relations/1']]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->body(), 'v2.docx'));
    }

    public function test_link_failure_keeps_the_value_with_a_warning(): void
    {
        $definition = ChecklistDefinition::create(['code' => 'manual', 'label' => 'Manual', 'data_type' => 'sharepoint_link', 'work_item_state' => 'Done']);
        Http::fake(fn (Request $r) => $r->method() === 'PATCH'
            ? Http::response(['message' => 'Sin permisos'], 403)
            : Http::response($this->workItemResponse(50)));

        $this->putJson("/api/work-items/50/checklist/{$definition->id}", ['value' => 'https://claro.sharepoint.com/a.docx'])
            ->assertOk()
            ->assertJsonPath('data.item.value', 'https://claro.sharepoint.com/a.docx')
            ->assertJsonPath('data.warning', fn ($w) => str_contains($w, 'no se pudo enlazar'));
    }

    public function test_sync_links_adds_only_missing_documents(): void
    {
        $manual = ChecklistDefinition::create(['code' => 'manual', 'label' => 'Manual', 'data_type' => 'sharepoint_link', 'work_item_state' => 'Done']);
        $minuta = ChecklistDefinition::create(['code' => 'minuta', 'label' => 'Minutograma', 'data_type' => 'sharepoint_link', 'work_item_state' => 'Done']);
        $text = ChecklistDefinition::create(['code' => 'nota', 'label' => 'Nota', 'data_type' => 'text', 'work_item_state' => 'Done']);
        $metadata = WorkItemMetadata::forWorkItem(50, 'Task');
        foreach ([[$manual, 'https://claro.sharepoint.com/manual.docx'], [$minuta, 'https://claro.sharepoint.com/minuta.xlsx'], [$text, 'hola']] as [$d, $value]) {
            ChecklistStatus::create(['work_item_metadata_id' => $metadata->id, 'checklist_definition_id' => $d->id, 'value' => $value, 'status' => 'done']);
        }

        Http::fake([
            '*/_apis/wit/workitems/50*' => Http::response($this->workItemResponse(50, [], [
                ['rel' => 'Hyperlink', 'url' => 'https://claro.sharepoint.com/manual.docx'],
            ])),
        ]);

        $this->postJson('/api/work-items/50/checklist/sync-links')
            ->assertOk()
            ->assertJsonPath('data', ['added' => 1, 'already_linked' => 1, 'total' => 2]);

        Http::assertSentCount(3); // responsable + work item + un PATCH
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->body(), 'minuta.xlsx'));
    }
}
