<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkItemCommentAndStateTest extends TestCase
{
    public function test_adds_comment_as_html(): void
    {
        Http::fake([
            '*/_apis/wit/workItems/10/comments*' => Http::response([
                'id' => 99, 'text' => '<p>x</p>', 'createdBy' => ['displayName' => 'Ana'], 'createdDate' => '2026-10-26T10:00:00Z',
            ]),
        ]);

        $this->postJson('/api/work-items/10/comments', ['text' => "Línea 1\nLínea **2** <script>x</script>"])
            ->assertCreated()
            ->assertJsonPath('data.id', 99);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'api-version=7.1-preview.4')
                && str_contains($request['text'], 'Línea 1<br>')
                && str_contains($request['text'], '<strong>2</strong>')
                && ! str_contains($request['text'], '<script>');
        });
    }

    public function test_show_includes_linked_pull_requests(): void
    {
        Http::fake([
            '*/_apis/wit/workItems/10/comments*' => Http::response(['comments' => []]),
            '*/_apis/wit/workItems/10/updates*' => Http::response(['value' => []]),
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10, [], [
                ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/proj%2Frepo-1%2F700'],
                ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/proj/repo-1/701'],
                ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/Ref/proj/repo-1/GBfeature/login'],
            ])),
            '*/pullrequests/700*' => Http::response([
                'pullRequestId' => 700, 'title' => 'Login', 'status' => 'active', 'isDraft' => true,
                'sourceRefName' => 'refs/heads/feature/login', 'targetRefName' => 'refs/heads/develop',
                'repository' => ['name' => 'repo-web'], 'createdBy' => ['displayName' => 'Ana'],
            ]),
            '*/pullrequests/701*' => Http::response(['message' => 'No existe'], 404),
        ]);

        $this->getJson('/api/work-items/10')
            ->assertOk()
            ->assertJsonCount(2, 'data.pull_requests')
            ->assertJsonPath('data.pull_requests.0.id', 700)
            ->assertJsonPath('data.pull_requests.0.isDraft', true)
            ->assertJsonPath('data.pull_requests.0.sourceBranch', 'feature/login')
            ->assertJsonPath('data.pull_requests.0.repository', 'repo-web')
            ->assertJsonPath('data.pull_requests.1.id', 701)
            ->assertJsonPath('data.pull_requests.1.error', fn ($error) => str_contains($error, 'No existe'));
    }

    public function test_comment_text_is_required(): void
    {
        $this->postJson('/api/work-items/10/comments', ['text' => ''])->assertUnprocessable();
    }

    public function test_updates_state_with_json_patch(): void
    {
        Http::fake([
            ...$this->fakeWorkItemTypes(),
            '*/_apis/wit/workitems/10*' => Http::response($this->workItemResponse(10, ['System.State' => 'Done'])),
        ]);

        $this->patchJson('/api/work-items/10/state', ['state' => 'Done', 'type' => 'Task'])
            ->assertOk()
            ->assertJsonPath('data.state', 'Done');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request->header('Content-Type')[0] === 'application/json-patch+json'
            && $request->data() === [['op' => 'add', 'path' => '/fields/System.State', 'value' => 'Done']]);
    }

    public function test_rejects_state_not_valid_for_type(): void
    {
        Http::fake($this->fakeWorkItemTypes());

        $this->patchJson('/api/work-items/10/state', ['state' => 'Committed', 'type' => 'Task'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('state');

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
    }

    public function test_azure_rule_error_is_returned_as_422(): void
    {
        Http::fake([
            ...$this->fakeWorkItemTypes(),
            '*/_apis/wit/workitems/10*' => Http::response(['message' => 'TF401320: Rule Error for field Remaining Work'], 400),
        ]);

        $this->patchJson('/api/work-items/10/state', ['state' => 'Done', 'type' => 'Task'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Azure DevOps rechazó el cambio de estado: TF401320: Rule Error for field Remaining Work');
    }

    public function test_lists_work_items_with_filters(): void
    {
        Http::fake([
            '*/_apis/wit/wiql*' => Http::response(['workItems' => [['id' => 1], ['id' => 2], ['id' => 3]]]),
            '*/_apis/wit/workitems?*' => Http::response(['value' => [
                $this->workItemResponse(3, ['System.AssignedTo' => ['displayName' => 'Ana']]),
            ]]),
        ]);

        $this->getJson('/api/work-items?page=2&limit=2&state[]=In%20Progress&assigned_to=ana@x.com')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('totalPages', 2)
            ->assertJsonPath('data.0.assigned_to', 'Ana');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/wit/workitems?')
            && str_contains(urldecode($request->url()), 'ids=3'));
        Http::assertSent(fn (Request $request) => str_contains($request['query'] ?? '', "[System.AssignedTo] = 'ana@x.com'")
            && str_contains($request['query'], "[System.State] In ('In Progress')"));
    }
}
