<?php

namespace Tests\Feature;

use App\Models\AgentApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClosureCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-26 20:00:00', 'UTC'));

        $this->actingAsAzureUser();
    }

    protected function pullRequest(int $id, string $status = 'completed'): array
    {
        return [
            'pullRequestId' => $id, 'title' => "Cambio {$id}", 'status' => $status,
            'creationDate' => '2026-10-08T15:00:00Z', 'closedDate' => $status === 'completed' ? '2026-10-19T15:00:00Z' : null,
            'sourceRefName' => "refs/heads/feature/60-{$id}", 'targetRefName' => 'refs/heads/develop',
            'repository' => ['id' => 'repo', 'name' => 'repo-api', 'project' => ['id' => 'proj-guid']],
        ];
    }

    protected function fakeAzure(string $state = 'Done', string $prStatus = 'completed'): void
    {
        Http::fake([
            ...$this->fakeWorkItemTypes(),
            '*/_apis/wit/workItems/60/updates*' => Http::response(['value' => []]),
            '*/_apis/wit/workitems/60*' => Http::response($this->workItemResponse(60, [
                'System.State' => $state,
                'System.CreatedDate' => '2026-10-02T15:00:00Z',
                'Microsoft.VSTS.Common.ActivatedDate' => '2026-10-05T15:00:00Z',
                'Microsoft.VSTS.Common.ClosedDate' => '2026-10-20T15:00:00Z',
            ], [
                ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/proj-guid%2Frepo%2F800'],
            ])),
            '*/pullRequests/*/commits*' => Http::response(['value' => [
                ['commitId' => 'bbbbbbb2', 'comment' => 'Agrega validación de token', 'author' => ['name' => 'Ana', 'date' => '2026-10-18T14:00:00Z']],
                ['commitId' => 'aaaaaaa1', 'comment' => 'Crea endpoint de login', 'author' => ['name' => 'Ana', 'date' => '2026-10-06T14:00:00Z']],
                ['commitId' => 'ccccccc3', 'comment' => "Merge branch 'develop'", 'author' => ['name' => 'Ana', 'date' => '2026-10-10T14:00:00Z']],
            ]]),
            '*/pullRequests/*/iterations/2/changes*' => Http::response(['changeEntries' => [
                ['item' => ['path' => '/app/Login.php', 'gitObjectType' => 'blob']],
                ['item' => ['path' => '/app', 'gitObjectType' => 'tree']],
            ]]),
            '*/pullRequests/*/iterations*' => Http::response(['value' => [['id' => 1], ['id' => 2]]]),
            '*/pullrequests/800*' => Http::response($this->pullRequest(800, $prStatus)),
            '*/pullrequests/801*' => Http::response($this->pullRequest(801)),
            '*/_apis/wit/workItems/60/comments*' => Http::response(['id' => 900, 'text' => '<p>ok</p>']),
        ]);
    }

    protected function prs(int ...$ids): array
    {
        return ['pull_requests' => array_map(fn ($id) => ['repositoryId' => 'repo', 'pullRequestId' => $id], $ids)];
    }

    public function test_creates_one_proposal_per_pull_request_and_links_missing_ones(): void
    {
        $this->fakeAzure();

        $response = $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800, 801))
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action_code', 'closure_comment')
            ->assertJsonPath('data.0.status', 'Pendiente')
            ->assertJsonPath('data.0.pull_request.id', 800)
            ->assertJsonPath('data.1.pull_request.id', 801);

        $proposal = $response->json('data.0.proposal');
        $this->assertStringStartsWith('**🤖 Resumen de cierre — [PR !800 Cambio 800]', $proposal);
        $this->assertStringContainsString('creado 02/10/2026 · inicio 05/10/2026 · cerrado 20/10/2026', $proposal);
        $this->assertStringContainsString('PR: creado 08/10/2026 · completado 19/10/2026', $proposal);
        $this->assertStringContainsString('Commits: 2, del 06/10/2026 al 18/10/2026', $proposal);
        $this->assertStringContainsString("- Crea endpoint de login\n- Agrega validación de token", $proposal);
        $this->assertStringNotContainsString('Merge branch', $proposal);
        $this->assertStringContainsString('`app/Login.php`', $proposal);

        // Solo se enlaza el PR que faltaba; nada se publica
        $linked = fn (Request $r) => $r->method() === 'PATCH' ? ($r->data()[0]['value']['url'] ?? null) : null;
        Http::assertSent(fn (Request $r) => $linked($r) === 'vstfs:///Git/PullRequestId/proj-guid/repo/801');
        Http::assertNotSent(fn (Request $r) => $linked($r) === 'vstfs:///Git/PullRequestId/proj-guid/repo/800');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/comments'));
    }

    public function test_requires_a_finished_work_item(): void
    {
        $this->fakeAzure(state: 'In Progress');

        $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_completed');
    }

    public function test_requires_a_pull_request(): void
    {
        $this->fakeAzure();

        $this->postJson('/api/work-items/60/agent/closure-comment', ['pull_requests' => []])
            ->assertJsonValidationErrors('pull_requests');
    }

    public function test_rejects_pull_requests_that_are_not_completed(): void
    {
        $this->fakeAzure(prStatus: 'active');

        $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'pull_request_not_completed');

        $this->assertSame(0, AgentApproval::count());
    }

    public function test_publishes_once_per_pull_request_and_does_not_expire(): void
    {
        $this->fakeAzure();

        $id = $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800))->json('data.0.id');
        $this->postJson("/api/agent-approvals/{$id}/approve", ['text' => 'Cierre revisado'])->assertOk();

        // Otro PR del mismo WI el mismo día sí se puede publicar
        $other = $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(801))->json('data.0.id');
        $this->postJson("/api/agent-approvals/{$other}/approve", ['text' => 'Cierre 801'])->assertOk();

        // El mismo PR otra vez: se avisa
        $again = $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800))->json('data.0.id');
        $this->postJson("/api/agent-approvals/{$again}/approve", ['text' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_published');

        // Al día siguiente no vence (no es un resumen "del día")
        Carbon::setTestNow(Carbon::parse('2026-10-28 14:00:00', 'UTC'));
        $this->getJson('/api/agent-approvals?status=Pendiente')->assertJsonCount(1, 'data');
    }

    public function test_new_closure_proposal_only_replaces_the_same_pull_request(): void
    {
        $this->fakeAzure();

        [$first, $second] = array_column($this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800, 801))->json('data'), 'id');
        $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800));

        $this->assertSame(AgentApproval::REPLACED, AgentApproval::find($first)->status);
        $this->assertSame(AgentApproval::PENDING, AgentApproval::find($second)->status);
    }

    public function test_regenerate_closure_proposal(): void
    {
        $this->fakeAzure();

        $id = $this->postJson('/api/work-items/60/agent/closure-comment', $this->prs(800))->json('data.0.id');

        $this->postJson("/api/agent-approvals/{$id}/regenerate")
            ->assertOk()
            ->assertJsonPath('data.generator', 'template');
    }
}
