<?php

namespace Tests\Feature;

use App\Jobs\RunDailyCommentAgent;
use App\Models\AgentApproval;
use App\Services\Agents\CommentGenerator;
use App\Services\Agents\DailyProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AgentCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 26/10/2026 15:00 en Bogotá
        Carbon::setTestNow(Carbon::parse('2026-10-26 20:00:00', 'UTC'));
    }

    protected function fakeAzure(array $commits): void
    {
        Http::fake([
            '*/_apis/wit/workitems/50*' => Http::response($this->workItemResponse(50, [], [
                ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/p/repo/700'],
            ])),
            '*/pullRequests/700/commits*' => Http::response(['value' => $commits]),
            '*/pullrequests/700*' => Http::response([
                'pullRequestId' => 700, 'title' => 'Login', 'status' => 'active',
                'sourceRefName' => 'refs/heads/feature/login', 'targetRefName' => 'refs/heads/develop',
                'repository' => ['name' => 'repo-web'],
            ]),
            '*/commits/*/changes*' => Http::response(['changes' => [
                ['item' => ['path' => '/src/Login.php', 'gitObjectType' => 'blob']],
                ['item' => ['path' => '/src', 'gitObjectType' => 'tree']],
            ]]),
            '*/_apis/wit/workItems/50/comments*' => Http::response(['id' => 555, 'text' => '<p>ok</p>']),
            '*/_apis/connectionData*' => Http::response(['authenticatedUser' => ['providerDisplayName' => 'Ana']]),
        ]);
    }

    protected function commit(string $id, string $message, string $date): array
    {
        return ['commitId' => $id, 'comment' => $message, 'author' => ['name' => 'Ana', 'date' => $date]];
    }

    public function test_proposal_with_todays_commits_is_not_published(): void
    {
        $this->fakeAzure([
            $this->commit('aaaaaaa1', 'Valida el login', '2026-10-26T14:00:00Z'),
            $this->commit('bbbbbbb2', 'Commit de ayer', '2026-10-25T14:00:00Z'),
            $this->commit('ccccccc3', "Merge branch 'develop' into feature/login", '2026-10-26T15:00:00Z'),
        ]);

        $response = $this->postJson('/api/work-items/50/agent/daily-comment')
            ->assertCreated()
            ->assertJsonPath('data.has_commits', true)
            ->assertJsonPath('data.status', 'Pendiente')
            ->assertJsonPath('data.generator', 'template');

        $proposal = $response->json('data.proposal');
        $this->assertStringStartsWith('**🤖 Resumen automático del día (26/10/2026)**', $proposal);
        $this->assertStringContainsString('09:00 — Valida el login', $proposal);
        $this->assertStringNotContainsString('Commit de ayer', $proposal);
        $this->assertStringNotContainsString('Merge branch', $proposal);
        $this->assertStringContainsString('`src/Login.php`', $proposal);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/comments'));
        $this->assertDatabaseHas('agent_runs', ['azure_work_item_id' => '50', 'status' => 'proposal', 'trigger' => 'manual']);
    }

    public function test_without_commits_requires_manual_comment(): void
    {
        $this->fakeAzure([$this->commit('bbbbbbb2', 'Commit de ayer', '2026-10-25T14:00:00Z')]);

        $this->postJson('/api/work-items/50/agent/daily-comment')
            ->assertCreated()
            ->assertJsonPath('data.has_commits', false)
            ->assertJsonPath('data.action_code', 'daily_comment_manual')
            ->assertJsonPath('data.proposal', null);

        $this->assertDatabaseHas('agent_runs', ['status' => 'manual_required']);
    }

    public function test_approve_publishes_once_per_day(): void
    {
        $this->fakeAzure([$this->commit('aaaaaaa1', 'Valida el login', '2026-10-26T14:00:00Z')]);

        $id = $this->postJson('/api/work-items/50/agent/daily-comment')->json('data.id');

        $this->postJson("/api/agent-approvals/{$id}/approve", ['text' => 'Texto revisado'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Publicado')
            ->assertJsonPath('data.final_text', 'Texto revisado')
            ->assertJsonPath('data.resolved_by', 'Ana');

        $this->assertDatabaseHas('agent_runs', ['azure_comment_id' => '555']);

        // Ya resuelta
        $this->postJson("/api/agent-approvals/{$id}/approve", ['text' => 'otra vez'])->assertStatus(409);

        // Nueva propuesta el mismo día: se avisa y se puede forzar
        $second = $this->postJson('/api/work-items/50/agent/daily-comment')->json('data.id');
        $this->postJson("/api/agent-approvals/{$second}/approve", ['text' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_published');
        $this->postJson("/api/agent-approvals/{$second}/approve", ['text' => 'x', 'force' => true])->assertOk();
    }

    public function test_new_proposal_replaces_pending_one(): void
    {
        $this->fakeAzure([]);

        $first = $this->postJson('/api/work-items/50/agent/daily-comment')->json('data.id');
        $this->postJson('/api/work-items/50/agent/daily-comment');

        $this->assertSame(AgentApproval::REPLACED, AgentApproval::find($first)->status);
        $this->getJson('/api/agent-approvals/pending-count')->assertJsonPath('data.count', 1);
    }

    public function test_reject_and_expire_stale_proposals(): void
    {
        $this->fakeAzure([]);

        $id = $this->postJson('/api/work-items/50/agent/daily-comment')->json('data.id');
        $this->postJson("/api/agent-approvals/{$id}/reject")->assertJsonPath('data.status', 'Rechazado');

        $stale = $this->postJson('/api/work-items/50/agent/daily-comment')->json('data.id');
        Carbon::setTestNow(Carbon::parse('2026-10-27 14:00:00', 'UTC'));

        $this->getJson('/api/agent-approvals?status=Pendiente')->assertJsonCount(0, 'data');
        $this->assertSame(AgentApproval::EXPIRED, AgentApproval::find($stale)->status);
    }

    public function test_falls_back_to_template_when_copilot_fails(): void
    {
        $this->fakeAzure([$this->commit('aaaaaaa1', 'Valida el login', '2026-10-26T14:00:00Z')]);
        $this->app->bind(CommentGenerator::class, fn () => new class implements CommentGenerator
        {
            public function generate(DailyProgress $progress): string
            {
                throw new \RuntimeException('Copilot Studio no respondió');
            }

            public function name(): string
            {
                return 'copilot_studio';
            }
        });

        $this->postJson('/api/work-items/50/agent/daily-comment')
            ->assertJsonPath('data.generator', 'template')
            ->assertJsonPath('data.fallback_reason', 'Copilot Studio no respondió');
    }

    public function test_batch_queues_one_job_per_open_work_item(): void
    {
        Queue::fake();
        Http::fake([
            '*/_apis/wit/wiql*' => Http::response(['workItems' => [['id' => 50], ['id' => 51]]]),
        ]);

        $batchId = $this->postJson('/api/agent/daily-comment/batch')
            ->assertAccepted()
            ->assertJsonPath('data.count', 2)
            ->json('data.batch_id');

        Queue::assertPushed(RunDailyCommentAgent::class, 2);
        Http::assertSent(fn (Request $request) => str_contains($request['query'], "Not In ('Done', 'Closed', 'Removed')"));
        $this->getJson("/api/agent-runs?batch_id={$batchId}")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'queued');
    }
}
