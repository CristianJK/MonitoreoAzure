<?php

namespace Tests\Unit;

use App\Services\AzureDevOpsService;
use Tests\TestCase;

class AzureDevOpsServiceTest extends TestCase
{
    public function test_escapes_single_quotes_in_wiql(): void
    {
        $this->assertSame("O''Brien", AzureDevOpsService::escapeWiql("O'Brien"));
    }

    public function test_builds_query_with_escaped_filters(): void
    {
        $query = app(AzureDevOpsService::class)->buildWorkItemsQuery([
            'assigned_to' => "ana' Or 1=1 --@x.com",
            'states' => ['In Progress', "Do'ne"],
            'types' => ['Task'],
            'search' => "fix 'login'",
        ]);

        $this->assertStringContainsString("[System.AssignedTo] = 'ana'' Or 1=1 --@x.com'", $query);
        $this->assertStringContainsString("[System.State] In ('In Progress', 'Do''ne')", $query);
        $this->assertStringContainsString("[System.WorkItemType] In ('Task')", $query);
        $this->assertStringContainsString("[System.Title] Contains 'fix ''login'''", $query);
    }

    public function test_builds_query_for_me_and_numeric_search(): void
    {
        $query = app(AzureDevOpsService::class)->buildWorkItemsQuery(['search' => '825011']);

        $this->assertStringContainsString('[System.AssignedTo] = @Me', $query);
        $this->assertStringContainsString('[System.Id] = 825011', $query);
    }

    public function test_open_query_excludes_finished_states(): void
    {
        $query = app(AzureDevOpsService::class)->buildWorkItemsQuery([], excludeFinished: true);

        $this->assertStringContainsString("Not In ('Done', 'Closed', 'Removed')", $query);
    }

    public function test_parses_artifact_links_in_both_formats(): void
    {
        $links = AzureDevOpsService::parseArtifactLinks([
            ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/proj-guid/repo-guid/94819'],
            ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/proj-guid%2Frepo-guid%2F94820'],
            ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/Ref/proj-guid%2Frepo-guid%2FGBfeature%2Flogin'],
            ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/Ref/Proyecto%20Test/repo-guid/GBhotfix%2Fx'],
            ['rel' => 'System.LinkTypes.Hierarchy-Reverse', 'url' => 'https://dev.azure.com/x/_apis/wit/workItems/1'],
            ['rel' => 'ArtifactLink', 'url' => 'vstfs:///Git/PullRequestId/proj-guid/repo-guid/94819'],
        ]);

        $this->assertSame([94819, 94820], array_column($links['pull_requests'], 'pullRequestId'));
        $this->assertSame('repo-guid', $links['pull_requests'][1]['repositoryId']);
        $this->assertSame(['feature/login', 'hotfix/x'], array_column($links['branches'], 'branch'));
        $this->assertSame('Proyecto Test', $links['branches'][1]['project']);
    }
}
