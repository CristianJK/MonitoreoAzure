<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PullRequestLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAzureUser();
    }

    protected function pr(int $id, string $title, string $branch, string $status = 'completed'): array
    {
        return [
            'pullRequestId' => $id, 'title' => $title, 'status' => $status,
            'sourceRefName' => "refs/heads/{$branch}", 'targetRefName' => 'refs/heads/develop',
            'closedDate' => '2026-10-19T15:00:00Z',
            'repository' => ['id' => 'repo', 'name' => 'repo-api', 'project' => ['id' => 'proj-guid']],
        ];
    }

    public function test_lists_pull_requests_by_status_and_search(): void
    {
        Http::fake([
            '*/repositories/repo/pullrequests*' => Http::response(['value' => [
                $this->pr(10, 'Login con PAT', 'feature/login'),
                $this->pr(11, 'Reportes', 'feature/reportes'),
            ]]),
        ]);

        $this->getJson('/api/repositories/repo/pull-requests?status=completed&search=login')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.pullRequestId', 10)
            ->assertJsonPath('0.status', 'completed')
            ->assertJsonPath('0.closedDate', '2026-10-19T15:00:00Z')
            ->assertJsonPath('0.artifactId', 'vstfs:///Git/PullRequestId/proj-guid/repo/10');

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'searchCriteria.status=completed'));

        $this->getJson('/api/repositories/repo/pull-requests?status=otro')->assertJsonValidationErrors('status');
    }

    public function test_suggests_pull_requests_not_yet_linked(): void
    {
        Http::fake([
            '*/_apis/wit/workitems/123*' => Http::response($this->workItemResponse(123, [], [
                ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/Ref/proj-guid%2Frepo%2FGBfeature%2Fmi-rama'],
                ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/proj-guid%2Frepo%2F30'],
            ])),
            '*/repositories/repo/pullrequests*' => Http::response(['value' => [$this->pr(20, 'Mi rama', 'feature/mi-rama')]]),
            '*/_apis/git/pullrequests*' => Http::response(['value' => [
                $this->pr(30, 'Ya enlazado #123', 'feature/x'),
                $this->pr(31, 'Corrige filtro AB#123', 'bugfix/filtro'),
                $this->pr(32, 'Otro', 'feature/123-reportes'),
                $this->pr(33, 'No relacionado #1234', 'feature/1234-x'),
            ]]),
        ]);

        $response = $this->getJson('/api/work-items/123/pull-request-suggestions')->assertOk();

        $this->assertSame([20, 31, 32], array_column($response->json('data'), 'pullRequestId'));
        $this->assertStringContainsString('rama enlazada', $response->json('data.0.matchReason'));
    }
}
