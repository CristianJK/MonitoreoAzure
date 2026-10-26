<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente de la API REST 7.1 de Azure DevOps.
 *
 * Convención: los métodos públicos no lanzan excepciones; ante un error
 * devuelven ['error' => mensaje, 'status' => código HTTP de Azure].
 */
class AzureDevOpsService
{
    protected const API_VERSION = '7.1';

    protected const COMMENTS_API_VERSION = '7.1-preview.4';

    protected string $organizationUrl;

    protected string $project;

    protected string $pat;

    public function __construct()
    {
        $orgValue = (string) config('azure.org');

        if (! str_starts_with($orgValue, 'http')) {
            $this->organizationUrl = 'https://dev.azure.com/'.trim($orgValue, '/');
        } else {
            $this->organizationUrl = rtrim($orgValue, '/');
        }

        $this->project = (string) config('azure.project');
        $this->pat = (string) config('azure.pat');
    }

    /**
     * Cliente para recursos a nivel de PROYECTO.
     */
    protected function client(string $apiVersion = self::API_VERSION): PendingRequest
    {
        return $this->request("{$this->organizationUrl}/".rawurlencode($this->project).'/_apis/', $apiVersion);
    }

    /**
     * Cliente para recursos a nivel de ORGANIZACIÓN (agentes, equipos, identidad).
     */
    protected function orgClient(string $apiVersion = self::API_VERSION): PendingRequest
    {
        return $this->request("{$this->organizationUrl}/_apis/", $apiVersion);
    }

    protected function request(string $baseUrl, string $apiVersion): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withBasicAuth('', $this->pat)
            ->acceptJson()
            ->timeout(config('azure.timeout', 30))
            ->withQueryParameters(['api-version' => $apiVersion])
            ->throw();
    }

    protected function formatError(\Throwable $e, string $prefix): array
    {
        $detail = $e->getMessage();
        $status = 500;

        if ($e instanceof RequestException) {
            $status = $e->response->status();
            $detail = $e->response->json('message') ?? $e->response->body();
        }

        return ['error' => "{$prefix}: {$detail}", 'status' => $status];
    }

    /**
     * Escapa un valor para usarlo dentro de comillas simples en WIQL.
     */
    public static function escapeWiql(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    // ---------------------------------------------------------------------
    // Work items: consulta
    // ---------------------------------------------------------------------

    /**
     * @param  array{assigned_to?: ?string, states?: string[], types?: string[], search?: ?string}  $filters
     */
    public function getActiveWorkItems($page = 1, $limit = 10, array $filters = [])
    {
        try {
            $page = max(1, (int) $page);
            $limit = max(1, (int) $limit);
            $query = $this->buildWorkItemsQuery($filters);

            $ids = $this->runWiql($query);
            $total = count($ids);

            $summaryFilters = $filters;
            unset($summaryFilters['states']);
            $summary = Cache::remember('azure.summary.'.md5(json_encode($summaryFilters)), 60, fn () => [
                'done' => count($this->runWiql($this->buildWorkItemsQuery([...$summaryFilters, 'states' => ['Done']]))),
                'inProgress' => count($this->runWiql($this->buildWorkItemsQuery([...$summaryFilters, 'states' => ['In Progress']]))),
                'toDo' => count($this->runWiql($this->buildWorkItemsQuery([...$summaryFilters, 'states' => ['To Do']]))),
            ]);

            $pageIds = array_slice($ids, ($page - 1) * $limit, $limit);

            return [
                'items' => $pageIds ? $this->getWorkItemsDetails($pageIds) : [],
                'total' => $total,
                'summary' => $summary,
            ];
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al consultar los Work Items');
        }
    }

    /**
     * IDs de los work items abiertos (no Done/Closed/Removed) de un usuario.
     * Se usa para el lote del agente de comentario diario.
     */
    public function getOpenWorkItemIds(?string $assignedTo = '@Me'): array
    {
        try {
            $query = $this->buildWorkItemsQuery(['assigned_to' => $assignedTo], excludeFinished: true);

            return $this->runWiql($query);
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al consultar los Work Items abiertos');
        }
    }

    public function buildWorkItemsQuery(array $filters, bool $excludeFinished = false): string
    {
        $conditions = $excludeFinished
            ? ["[System.State] Not In ('Done', 'Closed', 'Removed')"]
            : ["[System.State] <> 'Closed'"];

        $assignedTo = $filters['assigned_to'] ?? '@Me';
        if ($assignedTo) {
            $conditions[] = strtolower($assignedTo) === '@me'
                ? '[System.AssignedTo] = @Me'
                : "[System.AssignedTo] = '".self::escapeWiql($assignedTo)."'";
        }

        if (! empty($filters['states'])) {
            $conditions[] = '[System.State] In ('.$this->wiqlList($filters['states']).')';
        }

        if (! empty($filters['types'])) {
            $conditions[] = '[System.WorkItemType] In ('.$this->wiqlList($filters['types']).')';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = ctype_digit($search)
                ? '[System.Id] = '.(int) $search
                : "[System.Title] Contains '".self::escapeWiql($search)."'";
        }

        return 'Select [System.Id] From WorkItems Where '
            .implode(' And ', $conditions)
            .' Order By [System.ChangedDate] Desc';
    }

    protected function wiqlList(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'".self::escapeWiql((string) $v)."'", $values));
    }

    protected function runWiql(string $query): array
    {
        $result = $this->client()->post('wit/wiql', ['query' => $query])->json();

        return array_column($result['workItems'] ?? [], 'id');
    }

    private function getWorkItemsDetails(array $ids)
    {
        return $this->client()->get('wit/workitems', ['ids' => implode(',', $ids)])->json('value') ?? [];
    }

    public function getWorkItemById($workItemId)
    {
        try {
            return $this->client()->get("wit/workitems/{$workItemId}", ['$expand' => 'all'])->json();
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al obtener el Work Item');
        }
    }

    public function getWorkItemComments($workItemId)
    {
        try {
            $comments = $this->client(self::COMMENTS_API_VERSION)
                ->get("wit/workItems/{$workItemId}/comments", ['$expand' => 'renderedText'])
                ->json('comments') ?? [];

            return array_map(fn ($c) => $this->formatComment($c), $comments);
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function formatComment(array $comment): array
    {
        return [
            'id' => $comment['id'] ?? null,
            'text' => $comment['renderedText'] ?? $comment['text'] ?? '',
            'createdBy' => $comment['createdBy'] ?? null,
            'createdDate' => $comment['createdDate'] ?? null,
        ];
    }

    public function getWorkItemUpdates($workItemId)
    {
        try {
            return $this->client()->get("wit/workItems/{$workItemId}/updates")->json('value') ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getWorkItemFullDetails($workItemId)
    {
        $workItem = $this->getWorkItemById($workItemId);
        if (isset($workItem['error'])) {
            return $workItem;
        }

        $comments = $this->getWorkItemComments($workItemId);
        $updates = $this->getWorkItemUpdates($workItemId);

        $fields = $workItem['fields'] ?? [];

        $history = [];
        foreach ($updates as $update) {
            $fieldsUpdate = $update['fields'] ?? [];
            if (! empty($fieldsUpdate)) {
                $history[] = [
                    'id' => $update['id'] ?? null,
                    'revisedBy' => $update['revisedBy'] ?? null,
                    'revisedDate' => $update['revisedDate'] ?? null,
                    'fields' => $fieldsUpdate,
                ];
            }
        }

        return [
            'id' => $workItem['id'],
            'title' => $fields['System.Title'] ?? 'Sin título',
            'state' => $fields['System.State'] ?? 'Desconocido',
            'type' => $fields['System.WorkItemType'] ?? '',
            'assigned_to' => $fields['System.AssignedTo']['displayName'] ?? 'Sin asignar',
            'assigned_to_obj' => $fields['System.AssignedTo'] ?? null,
            'description' => $fields['System.Description'] ?? '',
            'created_by' => $fields['System.CreatedBy'] ?? null,
            'created_date' => $fields['System.CreatedDate'] ?? null,
            'changed_by' => $fields['System.ChangedBy'] ?? null,
            'changed_date' => $fields['System.ChangedDate'] ?? null,
            'area_path' => $fields['System.AreaPath'] ?? null,
            'iteration_path' => $fields['System.IterationPath'] ?? null,
            // Azure devuelve los comentarios del más reciente al más antiguo
            'comments' => $comments,
            'history' => $history,
            'relations' => $workItem['relations'] ?? [],
            'pull_requests' => $this->getLinkedPullRequests($workItem['relations'] ?? []),
        ];
    }

    /**
     * Detalle de los PRs enlazados al work item. Si un PR no se puede consultar
     * (borrado, sin permisos) se devuelve igual con el error, para que se vea el enlace.
     */
    protected function getLinkedPullRequests(array $relations): array
    {
        return array_map(function (array $link) {
            $pr = $this->getPullRequest($link['repositoryId'], $link['pullRequestId']);

            return isset($pr['error'])
                ? ['id' => $link['pullRequestId'], 'repositoryId' => $link['repositoryId'], 'error' => $pr['error']]
                : $pr;
        }, self::parseArtifactLinks($relations)['pull_requests']);
    }

    // ---------------------------------------------------------------------
    // Work items: escritura
    // ---------------------------------------------------------------------

    /**
     * Publica un comentario. El texto se recibe en markdown y se envía como
     * HTML (el formato por defecto de la API de comentarios).
     */
    public function addWorkItemComment($workItemId, string $markdown)
    {
        try {
            $html = Str::markdown($markdown, [
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'renderer' => ['soft_break' => '<br>'],
            ]);

            $comment = $this->client(self::COMMENTS_API_VERSION)
                ->post("wit/workItems/{$workItemId}/comments", ['text' => $html])
                ->json();

            return $this->formatComment($comment);
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al publicar el comentario');
        }
    }

    public function updateWorkItemState($workItemId, string $state)
    {
        try {
            $workItem = $this->client()
                ->withBody(json_encode([
                    ['op' => 'add', 'path' => '/fields/System.State', 'value' => $state],
                ]), 'application/json-patch+json')
                ->patch("wit/workitems/{$workItemId}")
                ->json();

            return [
                'id' => $workItem['id'],
                'state' => $workItem['fields']['System.State'] ?? $state,
                'changed_date' => $workItem['fields']['System.ChangedDate'] ?? null,
            ];
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Azure DevOps rechazó el cambio de estado');
        }
    }

    // ---------------------------------------------------------------------
    // Tipos de work item y estados
    // ---------------------------------------------------------------------

    /**
     * Tipos de work item habilitados con sus estados (cache 24h).
     *
     * @return array<int, array{name: string, states: array}>
     */
    public function getWorkItemTypes()
    {
        try {
            return Cache::remember('azure.work_item_types', now()->addDay(), function () {
                $types = $this->client()->get('wit/workitemtypes')->json('value') ?? [];

                return collect($types)
                    ->reject(fn ($t) => ! empty($t['isDisabled']))
                    ->map(fn ($t) => [
                        'name' => $t['name'],
                        'states' => $this->fetchWorkItemTypeStates($t['name']),
                    ])
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al obtener los tipos de Work Item');
        }
    }

    /**
     * @return array<int, array{name: string, color: string, category: string}>
     */
    public function getWorkItemTypeStates(string $type)
    {
        $types = $this->getWorkItemTypes();
        if (isset($types['error'])) {
            return $types;
        }

        foreach ($types as $t) {
            if (strcasecmp($t['name'], $type) === 0) {
                return $t['states'];
            }
        }

        return ['error' => "El tipo de Work Item '{$type}' no existe en el proyecto", 'status' => 404];
    }

    protected function fetchWorkItemTypeStates(string $type): array
    {
        $states = $this->client()->get('wit/workitemtypes/'.rawurlencode($type).'/states')->json('value') ?? [];

        return array_map(fn ($s) => [
            'name' => $s['name'],
            'color' => '#'.ltrim($s['color'] ?? '', '#'),
            'category' => $s['category'] ?? '',
        ], $states);
    }

    // ---------------------------------------------------------------------
    // Usuarios
    // ---------------------------------------------------------------------

    /**
     * Busca entre los miembros de los equipos del proyecto (cache 1h).
     */
    public function searchUsers(string $search = '', int $limit = 20)
    {
        try {
            $members = Cache::remember('azure.team_members', now()->addHour(), function () {
                $project = rawurlencode($this->project);
                $teams = $this->orgClient()->get("projects/{$project}/teams", ['$top' => 200])->json('value') ?? [];

                return collect($teams)
                    ->flatMap(fn ($team) => $this->orgClient()
                        ->get("projects/{$project}/teams/{$team['id']}/members", ['$top' => 500])
                        ->json('value') ?? [])
                    ->map(fn ($m) => $m['identity'] ?? $m)
                    ->reject(fn ($i) => ! empty($i['isContainer']))
                    ->map(fn ($i) => [
                        'displayName' => $i['displayName'] ?? '',
                        'uniqueName' => $i['uniqueName'] ?? '',
                        'imageUrl' => $i['imageUrl'] ?? null,
                    ])
                    ->filter(fn ($u) => $u['uniqueName'] !== '')
                    ->unique('uniqueName')
                    ->sortBy('displayName', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();
            });

            $search = Str::lower(Str::ascii(trim($search)));

            return collect($members)
                ->filter(fn ($u) => $search === ''
                    || str_contains(Str::lower(Str::ascii($u['displayName'])), $search)
                    || str_contains(Str::lower($u['uniqueName']), $search))
                ->take($limit)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al buscar usuarios');
        }
    }

    /**
     * Usuario dueño del PAT (cache 1h).
     */
    public function getCurrentUser()
    {
        try {
            return Cache::remember('azure.current_user', now()->addHour(), function () {
                $data = $this->orgClient('7.1-preview')->get('connectionData')->json('authenticatedUser') ?? [];

                return [
                    'id' => $data['id'] ?? null,
                    'displayName' => $data['providerDisplayName'] ?? $data['customDisplayName'] ?? 'Usuario',
                    'uniqueName' => $data['properties']['Account']['$value'] ?? null,
                ];
            });
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al obtener el usuario actual');
        }
    }

    // ---------------------------------------------------------------------
    // Repositorios, ramas y Pull Requests
    // ---------------------------------------------------------------------

    public function linkBranchToWorkItem($workItemId, $repositoryId, $branchName)
    {
        try {
            $encodedBranch = rawurlencode($branchName);
            $artifactUrl = 'vstfs:///Git/Ref/'.rawurlencode($this->project)."/{$repositoryId}/GB{$encodedBranch}";

            return $this->addArtifactLink($workItemId, $artifactUrl, 'Branch');
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Fallo al enlazar la rama');
        }
    }

    public function getRepositories()
    {
        try {
            $repos = $this->client()->get('git/repositories')->json('value') ?? [];

            return array_map(fn ($repo) => [
                'id' => $repo['id'],
                'name' => $repo['name'],
                'default_branch' => str_replace('refs/heads/', '', $repo['defaultBranch'] ?? ''),
            ], $repos);
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al obtener repositorios');
        }
    }

    public function getBranches($repositoryId)
    {
        try {
            $refs = $this->client()->get("git/repositories/{$repositoryId}/refs", ['filter' => 'heads/'])->json('value') ?? [];

            return array_map(fn ($ref) => [
                'name' => str_replace('refs/heads/', '', $ref['name']),
                'objectId' => $ref['objectId'],
            ], $refs);
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al obtener ramas');
        }
    }

    public function getAgentsStatus()
    {
        try {
            $pools = $this->orgClient()->get('distributedtask/pools')->json('value') ?? [];

            $allAgents = [];

            foreach ($pools as $pool) {
                try {
                    $agents = $this->orgClient()
                        ->get("distributedtask/pools/{$pool['id']}/agents", ['includeAssignedRequest' => 'true'])
                        ->json('value') ?? [];

                    foreach ($agents as $agent) {
                        $allAgents[] = [
                            'id' => $agent['id'],
                            'name' => $agent['name'],
                            'version' => $agent['version'] ?? 'N/A',
                            'status' => $agent['status'] ?? 'unknown',
                            'is_busy' => isset($agent['assignedRequest']),
                            'pool_name' => $pool['name'],
                            'current_job' => $agent['assignedRequest']['planType'] ?? 'Ninguno',
                        ];
                    }
                } catch (\Throwable $subException) {
                    // Ignoramos pools sin acceso y continuamos
                    continue;
                }
            }

            return $allAgents;
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al obtener los agentes');
        }
    }

    /**
     * Obtiene los Pull Requests activos (excluye completed/abandoned) de un repositorio
     */
    public function getActivePullRequests($repositoryId)
    {
        try {
            $prs = $this->client()
                ->get("git/repositories/{$repositoryId}/pullrequests", ['searchCriteria.status' => 'active'])
                ->json('value') ?? [];

            return array_map(function ($pr) {
                $projectGuid = $pr['repository']['project']['id'];
                $repoGuid = $pr['repository']['id'];
                $prId = $pr['pullRequestId'];

                return [
                    'pullRequestId' => $prId,
                    'title' => $pr['title'],
                    'sourceRefName' => str_replace('refs/heads/', '', $pr['sourceRefName'] ?? ''),
                    'targetRefName' => str_replace('refs/heads/', '', $pr['targetRefName'] ?? ''),
                    'createdBy' => $pr['createdBy']['displayName'] ?? 'Desconocido',
                    'artifactId' => "vstfs:///Git/PullRequestId/{$projectGuid}/{$repoGuid}/{$prId}",
                ];
            }, $prs);
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Error al obtener Pull Requests');
        }
    }

    /**
     * Enlaza un Pull Request existente al Work Item
     */
    public function linkPullRequestToWorkItem($workItemId, $artifactId)
    {
        try {
            return $this->addArtifactLink($workItemId, $artifactId, 'Pull Request');
        } catch (RequestException $e) {
            // Si Azure dice que ya existe, lo tratamos como un éxito silencioso
            if (str_contains($e->response->body(), 'Relation already exists')) {
                return ['message' => 'El Pull Request ya se encontraba enlazado a esta tarea.'];
            }

            return $this->formatError($e, 'Fallo al enlazar el Pull Request');
        } catch (\Throwable $e) {
            return $this->formatError($e, 'Fallo al enlazar el Pull Request');
        }
    }

    protected function addArtifactLink($workItemId, string $url, string $name): array
    {
        return $this->client()
            ->withBody(json_encode([[
                'op' => 'add',
                'path' => '/relations/-',
                'value' => ['rel' => 'ArtifactLink', 'url' => $url, 'attributes' => ['name' => $name]],
            ]]), 'application/json-patch+json')
            ->patch("wit/workitems/{$workItemId}")
            ->json();
    }

    /**
     * Extrae los PRs y ramas enlazados a partir de las relaciones de un work item.
     *
     * Azure guarda los separadores codificados (%2F) en los links que crea, y esta
     * app los crea con "/". Se aceptan ambos formatos.
     *
     * @return array{pull_requests: array, branches: array}
     */
    public static function parseArtifactLinks(array $relations): array
    {
        $links = ['pull_requests' => [], 'branches' => []];

        foreach ($relations as $relation) {
            if (($relation['rel'] ?? '') !== 'ArtifactLink') {
                continue;
            }

            $url = $relation['url'] ?? '';

            if (str_starts_with($url, 'vstfs:///Git/PullRequestId/')) {
                $parts = explode('/', rawurldecode(substr($url, strlen('vstfs:///Git/PullRequestId/'))), 3);
                if (count($parts) === 3 && ctype_digit($parts[2])) {
                    $links['pull_requests'][] = [
                        'project' => $parts[0],
                        'repositoryId' => $parts[1],
                        'pullRequestId' => (int) $parts[2],
                    ];
                }
            } elseif (str_starts_with($url, 'vstfs:///Git/Ref/')) {
                $parts = explode('/', rawurldecode(substr($url, strlen('vstfs:///Git/Ref/'))), 3);
                if (count($parts) === 3 && str_starts_with($parts[2], 'GB')) {
                    $links['branches'][] = [
                        'project' => $parts[0],
                        'repositoryId' => $parts[1],
                        'branch' => substr($parts[2], 2),
                    ];
                }
            }
        }

        $links['pull_requests'] = array_values(array_unique($links['pull_requests'], SORT_REGULAR));
        $links['branches'] = array_values(array_unique($links['branches'], SORT_REGULAR));

        return $links;
    }

    public function getPullRequest(string $repositoryId, int $pullRequestId)
    {
        try {
            $pr = $this->client()->get("git/repositories/{$repositoryId}/pullrequests/{$pullRequestId}")->json();
            $repoName = $pr['repository']['name'] ?? $repositoryId;

            return [
                'id' => $pr['pullRequestId'],
                'title' => $pr['title'] ?? '',
                'status' => $pr['status'] ?? '',
                'isDraft' => (bool) ($pr['isDraft'] ?? false),
                'creationDate' => $pr['creationDate'] ?? null,
                'repositoryId' => $repositoryId,
                'repository' => $repoName,
                'sourceBranch' => str_replace('refs/heads/', '', $pr['sourceRefName'] ?? ''),
                'targetBranch' => str_replace('refs/heads/', '', $pr['targetRefName'] ?? ''),
                'createdBy' => $pr['createdBy']['displayName'] ?? null,
                'url' => "{$this->organizationUrl}/".rawurlencode($this->project).'/_git/'.rawurlencode($repoName)."/pullrequest/{$pullRequestId}",
            ];
        } catch (\Throwable $e) {
            return $this->formatError($e, "Error al obtener el Pull Request {$pullRequestId}");
        }
    }

    /**
     * Commits de un PR con fecha de autor >= $since (sin merges).
     */
    public function getPullRequestCommitsSince(string $repositoryId, int $pullRequestId, CarbonInterface $since)
    {
        try {
            $commits = $this->client()
                ->get("git/repositories/{$repositoryId}/pullRequests/{$pullRequestId}/commits", ['$top' => 100])
                ->json('value') ?? [];

            return $this->filterCommitsSince($commits, $since);
        } catch (\Throwable $e) {
            return $this->formatError($e, "Error al obtener los commits del Pull Request {$pullRequestId}");
        }
    }

    /**
     * Commits de una rama con fecha >= $since.
     */
    public function getBranchCommitsSince(string $repositoryId, string $branch, CarbonInterface $since)
    {
        try {
            $commits = $this->client()->get("git/repositories/{$repositoryId}/commits", [
                'searchCriteria.itemVersion.version' => $branch,
                'searchCriteria.itemVersion.versionType' => 'branch',
                'searchCriteria.fromDate' => $since->copy()->utc()->toIso8601String(),
                'searchCriteria.$top' => 100,
            ])->json('value') ?? [];

            return $this->filterCommitsSince($commits, $since);
        } catch (\Throwable $e) {
            return $this->formatError($e, "Error al obtener los commits de la rama {$branch}");
        }
    }

    protected function filterCommitsSince(array $commits, CarbonInterface $since): array
    {
        return collect($commits)
            ->filter(fn ($c) => isset($c['author']['date']) && Carbon::parse($c['author']['date'])->gte($since))
            // Los merges traen archivos de otras ramas y no representan avance propio
            ->reject(fn ($c) => preg_match('/^Merged? (branch|PR|pull request|remote-tracking)/i', $c['comment'] ?? ''))
            ->map(fn ($c) => [
                'commitId' => $c['commitId'],
                'message' => trim($c['comment'] ?? ''),
                'author' => $c['author']['name'] ?? '',
                'date' => $c['author']['date'],
            ])
            ->values()
            ->all();
    }

    /**
     * Rutas de archivos modificados en un commit (sin contenido).
     */
    public function getCommitChangedFiles(string $repositoryId, string $commitId, int $limit = 15): array
    {
        try {
            $changes = $this->client()
                ->get("git/repositories/{$repositoryId}/commits/{$commitId}/changes", ['top' => $limit])
                ->json('changes') ?? [];

            return collect($changes)
                ->filter(fn ($c) => ($c['item']['gitObjectType'] ?? 'blob') === 'blob')
                ->map(fn ($c) => $c['item']['path'] ?? '')
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Último build de una rama, o null si no hay builds o el PAT no tiene acceso.
     */
    public function getLatestBuild(string $branch): ?array
    {
        try {
            $build = $this->client()->get('build/builds', [
                'branchName' => 'refs/heads/'.$branch,
                '$top' => 1,
                'queryOrder' => 'queueTimeDescending',
            ])->json('value.0');

            if (! $build) {
                return null;
            }

            return [
                'id' => $build['id'],
                'number' => $build['buildNumber'] ?? '',
                'status' => $build['status'] ?? '',
                'result' => $build['result'] ?? null,
                'definition' => $build['definition']['name'] ?? '',
                'url' => $build['_links']['web']['href'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Nombre de un repositorio a partir de su id (cache 1h).
     */
    public function getRepositoryName(string $repositoryId): string
    {
        $names = Cache::remember('azure.repository_names', now()->addHour(), function () {
            $repos = $this->getRepositories();

            return isset($repos['error']) ? [] : array_column($repos, 'name', 'id');
        });

        return $names[$repositoryId] ?? $repositoryId;
    }
}
