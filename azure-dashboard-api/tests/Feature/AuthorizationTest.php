<?php

namespace Tests\Feature;

use App\Jobs\DispatchDailyComments;
use App\Jobs\RunDailyCommentAgent;
use App\Models\AgentApproval;
use App\Models\ChecklistDefinition;
use App\Models\User;
use App\Models\WorkItemMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Si se agrega una ruta a la API sin `auth:sanctum`, este test falla.
     */
    public function test_every_api_route_except_login_requires_a_session(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && $route->uri() !== 'api/login');

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());

            $this->json($method, $uri)->assertUnauthorized();
        }

        Http::assertNothingSent();
    }

    public static function writeEndpoints(): array
    {
        return [
            'comentario' => ['POST', '/api/work-items/10/comments', ['text' => 'Hola']],
            'estado' => ['PATCH', '/api/work-items/10/state', ['state' => 'Done', 'type' => 'Task']],
            'enlazar rama' => ['POST', '/api/work-items/10/link-branch', ['repository_id' => '4f1c9a2e-8b7d-4c3a-9e6f-1a2b3c4d5e6f', 'branch_name' => 'feature/x']],
            'enlazar PR' => ['POST', '/api/work-items/10/link-pr', ['artifact_id' => 'vstfs:///Git/PullRequestId/p/r/1']],
            'alistamiento' => ['PUT', '/api/work-items/10/checklist/{definition}', ['value' => true]],
            'agente' => ['POST', '/api/work-items/10/agent/daily-comment', []],
        ];
    }

    #[DataProvider('writeEndpoints')]
    public function test_cannot_modify_work_items_assigned_to_someone_else(string $method, string $uri, array $payload): void
    {
        $this->actingAsAzureUser();
        $definition = ChecklistDefinition::create(['code' => 'a', 'label' => 'A', 'data_type' => 'boolean', 'work_item_state' => 'Done']);
        Http::fake([
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10, [
                'System.AssignedTo' => $this->identity('id-beto', 'beto@empresa.com', 'Beto'),
            ])),
        ]);

        $this->json($method, str_replace('{definition}', (string) $definition->id, $uri), $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'not_owner')
            ->assertJsonPath('message', 'Solo puedes modificar work items asignados a ti.');

        // Solo se consultó el responsable: ninguna escritura llegó a Azure
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
        $this->assertDatabaseHas('audit_logs', ['azure_work_item_id' => '10', 'result' => 'denied', 'status' => 403]);
    }

    public function test_unassigned_work_items_are_read_only(): void
    {
        $this->actingAsAzureUser();
        Http::fake([
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10, ['System.AssignedTo' => null])),
        ]);

        $this->postJson('/api/work-items/10/comments', ['text' => 'Hola'])->assertForbidden();
    }

    public function test_ownership_also_matches_by_unique_name(): void
    {
        $this->actingAsAzureUser();
        Http::fake([
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10, [
                'System.AssignedTo' => ['uniqueName' => 'ANA@empresa.com', 'displayName' => 'Ana'],
            ])),
            '*/_apis/wit/workItems/10/comments*' => Http::response(['id' => 1, 'text' => '<p>Hola</p>']),
        ]);

        $this->postJson('/api/work-items/10/comments', ['text' => 'Hola'])->assertCreated();
    }

    public function test_writes_use_the_users_own_pat(): void
    {
        $this->actingAsAzureUser();
        Http::fake([
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10)),
            '*/_apis/wit/workItems/10/comments*' => Http::response(['id' => 1, 'text' => '<p>Hola</p>']),
        ]);

        $this->postJson('/api/work-items/10/comments', ['text' => 'Hola'])->assertCreated();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->header('Authorization')[0] === $this->basicAuth('pat-ana'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST api/work-items/{id}/comments', 'result' => 'ok']);
    }

    public function test_work_items_of_others_can_be_read(): void
    {
        $this->actingAsAzureUser();
        Http::fake([
            '*/_apis/wit/workItems/10/comments*' => Http::response(['comments' => []]),
            '*/_apis/wit/workItems/10/updates*' => Http::response(['value' => []]),
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10, [
                'System.AssignedTo' => $this->identity('id-beto', 'beto@empresa.com', 'Beto'),
            ])),
        ]);

        $this->getJson('/api/work-items/10')
            ->assertOk()
            ->assertJsonPath('data.assigned_to', 'Beto')
            ->assertJsonPath('data.permissions.can_edit', false);
    }

    public function test_only_admins_can_change_the_checklist_catalog(): void
    {
        $this->actingAsAzureUser(['azure_id' => 'id-beto', 'email' => 'beto@empresa.com', 'name' => 'Beto']);
        Http::fake($this->fakeWorkItemTypes());

        $this->getJson('/api/checklist-definitions')->assertOk();
        $this->postJson('/api/checklist-definitions', ['label' => 'X', 'data_type' => 'boolean', 'work_item_state' => 'Done'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los administradores pueden modificar el catálogo de alistamiento.');
        $this->patchJson('/api/checklist-definitions/reorder', ['ids' => []])->assertForbidden();
    }

    public function test_agent_inbox_only_shows_own_proposals(): void
    {
        $beto = User::factory()->create(['email' => 'beto@empresa.com']);
        $approval = WorkItemMetadata::forWorkItem(10, 'Task')->approvals()->create([
            'owner_user_id' => $beto->id,
            'action_code' => AgentApproval::ACTION_DAILY_COMMENT,
            'status' => AgentApproval::PENDING,
            'requested_by' => 'manual',
            'payload' => ['proposal' => 'x'],
        ]);

        $this->actingAsAzureUser();

        $this->getJson('/api/agent-approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/agent-approvals/pending-count')->assertJsonPath('data.count', 0);
        $this->postJson("/api/agent-approvals/{$approval->id}/approve", ['text' => 'x'])->assertForbidden();
        $this->postJson("/api/agent-approvals/{$approval->id}/reject")->assertForbidden();

        $this->assertSame(AgentApproval::PENDING, $approval->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_approval_is_denied_after_work_item_is_reassigned(): void
    {
        $ana = $this->actingAsAzureUser();
        $approval = WorkItemMetadata::forWorkItem(10, 'Task')->approvals()->create([
            'owner_user_id' => $ana->id,
            'action_code' => AgentApproval::ACTION_DAILY_COMMENT,
            'status' => AgentApproval::PENDING,
            'requested_by' => 'manual',
            'payload' => ['proposal' => 'x'],
        ]);
        Http::fake([
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10, [
                'System.AssignedTo' => $this->identity('id-beto', 'beto@empresa.com', 'Beto'),
            ])),
        ]);

        $this->postJson("/api/agent-approvals/{$approval->id}/approve", ['text' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_owner');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/comments'));
    }

    public function test_batch_cannot_target_another_user(): void
    {
        Queue::fake();
        $this->actingAsAzureUser();

        $this->postJson('/api/agent/daily-comment/batch', ['assigned_to' => 'beto@empresa.com'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_owner');

        Queue::assertNothingPushed();
    }

    public function test_scheduled_run_uses_each_users_pat(): void
    {
        Queue::fake([RunDailyCommentAgent::class]);
        $ana = User::factory()->create(['email' => 'ana@empresa.com', 'azure_pat' => 'pat-ana', 'agent_schedule_enabled' => true]);
        User::factory()->create(['email' => 'beto@empresa.com']);
        User::factory()->create(['email' => 'carla@empresa.com', 'agent_schedule_enabled' => true, 'azure_pat' => null]);
        Http::fake([
            '*/_apis/wit/wiql*' => Http::response(['workItems' => [['id' => 50]]]),
        ]);

        (new DispatchDailyComments)->handle();

        // Solo Ana (activó el programado y tiene PAT), con su PAT y @Me
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request['query'], '[System.AssignedTo] = @Me')
            && $request->header('Authorization')[0] === $this->basicAuth('pat-ana'));
        Queue::assertPushed(RunDailyCommentAgent::class, fn ($job) => $job->userId === $ana->id && $job->workItemId === 50);
        $this->assertDatabaseHas('agent_runs', ['azure_work_item_id' => '50', 'user_id' => $ana->id, 'status' => 'queued']);

        // El job no deja al usuario autenticado en el worker
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_queued_job_generates_proposal_with_owners_pat(): void
    {
        $ana = User::factory()->create(['email' => 'ana@empresa.com', 'azure_id' => 'id-ana', 'azure_pat' => 'pat-ana']);
        Http::fake([
            '*/_apis/wit/workitems/50*' => Http::response($this->workItemResponse(50)),
        ]);

        (new RunDailyCommentAgent(50, 'scheduled', null, $ana->id))->handle();

        $this->assertDatabaseHas('agent_approvals', ['owner_user_id' => $ana->id, 'status' => AgentApproval::PENDING]);
        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === $this->basicAuth('pat-ana'));
        $this->assertNull(Auth::guard('web')->user());
    }
}
