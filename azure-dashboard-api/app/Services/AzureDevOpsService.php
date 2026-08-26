<?php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
class AzureDevOpsService
{
protected $client;
    protected $orgClient; // <-- Nuevo cliente para la Organización
    protected $organizationUrl;
    protected $project;

    public function __construct()
    {
        $orgValue = env('AZURE_DEVOPS_ORG');
        
        if (!str_starts_with($orgValue, 'http')) {
            $this->organizationUrl = "https://dev.azure.com/" . trim($orgValue, '/');
        } else {
            $this->organizationUrl = rtrim($orgValue, '/');
        }

        $this->project = rawurlencode(env('AZURE_DEVOPS_PROJECT'));
        $pat = env('AZURE_DEVOPS_PAT');

        // Cliente para nivel de PROYECTO
        $this->client = new Client([
            // Aseguramos que termine en slash para que Guzzle concatene bien
            'base_uri' => "{$this->organizationUrl}/{$this->project}/_apis/",
            'auth'     => ['', $pat],
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        // Cliente para nivel de ORGANIZACIÓN (Agentes)
        $this->orgClient = new Client([
            'base_uri' => "{$this->organizationUrl}/_apis/",
            'auth'     => ['', $pat],
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getActiveWorkItems($page = 1, $limit = 10, $assignedTo = '@Me')
    {
        try {
            
            $page = max(1, (int) $page);
            $limit = max(1, (int) $limit);
            $query = $this->buildActiveWorkItemsQuery($assignedTo);

            $totalResponse = $this->client->post("wit/wiql?api-version=7.1", [
                'json' => ['query' => $query]
            ]);
            $totalResult = json_decode($totalResponse->getBody()->getContents(), true);
            $total = count($totalResult['workItems'] ?? []);

            $summary = [
                'done' => $this->countWorkItemsByState('Done', $assignedTo),
                'inProgress' => $this->countWorkItemsByState('In Progress', $assignedTo),
                'toDo' => $this->countWorkItemsByState('To Do', $assignedTo),
            ];

            $skip = ($page - 1) * $limit;
            $response = $this->client->post("wit/wiql?\$top={$limit}&\$skip={$skip}&api-version=7.1", [
                'json' => ['query' => $query]
            ]);

            $wiqlResult = json_decode($response->getBody()->getContents(), true);

            if (empty($wiqlResult['workItems'])) {
                return [
                    'items' => [],
                    'total' => $total,
                    'summary' => $summary,
                ];
            }

            $ids = array_column($wiqlResult['workItems'], 'id');
            return [
                'items' => $this->getWorkItemsDetails($ids),
                'total' => $total,
                'summary' => $summary,
            ];

        } catch (\Exception $e) {
            $urlAttempted = method_exists($e, 'getRequest') ? (string) $e->getRequest()->getUri() : 'URL desconocida';
            $errorDetail = $e->getMessage();
            
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorDetail = $e->getResponse()->getBody()->getContents();
            }
            
            return ['error' => "URL intentada: {$urlAttempted} | Error: {$errorDetail}"];
        }
    }

    private function buildActiveWorkItemsQuery($assignedTo)
    {
        $query = "Select [System.Id], [System.Title], [System.State] 
                  From WorkItems 
                  Where [System.State] <> 'Closed'";

        if ($assignedTo) {
            if (strtolower($assignedTo) === '@me') {
                $query .= " And [System.AssignedTo] = @Me";
            } else {
                $query .= " And [System.AssignedTo] = '{$assignedTo}'";
            }
        }

        return $query . " Order By [System.ChangedDate] Desc";
    }

    private function countWorkItemsByState($state, $assignedTo)
    {
        $query = "Select [System.Id]
                  From WorkItems
                  Where [System.State] = '{$state}'";

        if ($assignedTo) {
            if (strtolower($assignedTo) === '@me') {
                $query .= " And [System.AssignedTo] = @Me";
            } else {
                $query .= " And [System.AssignedTo] = '{$assignedTo}'";
            }
        }

        $response = $this->client->post("wit/wiql?api-version=7.1", [
            'json' => ['query' => $query]
        ]);
        $result = json_decode($response->getBody()->getContents(), true);

        return count($result['workItems'] ?? []);
    }

    private function getWorkItemsDetails(array $ids)
    {
        $idsString = implode(',', $ids);
        
        $response = $this->client->get("wit/workitems?ids={$idsString}&api-version=7.1");
        
        return json_decode($response->getBody()->getContents(), true)['value'] ?? [];
    }

    public function linkBranchToWorkItem($workItemId, $repositoryId, $branchName)
    {
        try {
            
            $encodedBranch = rawurlencode($branchName);
            $artifactUrl = "vstfs:///Git/Ref/{$this->project}/{$repositoryId}/GB{$encodedBranch}";

            $payload = [
                [
                    "op" => "add",
                    "path" => "/relations/-",
                    "value" => [
                        "rel" => "ArtifactLink",
                        "url" => $artifactUrl,
                        "attributes" => [
                            "name" => "Branch"
                        ]
                    ]
                ]
            ];

            
            $response = $this->client->patch("wit/workitems/{$workItemId}?api-version=7.1", [
                'headers' => ['Content-Type' => 'application/json-patch+json'],
                'json' => $payload
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (\Exception $e) {
            $errorDetail = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorDetail = $e->getResponse()->getBody()->getContents();
            }
            return ['error' => "Fallo al enlazar la rama: {$errorDetail}"];
        }
    }
    

    public function getRepositories()
    {
        try {
            
            $response = $this->client->get("git/repositories?api-version=7.1");
            $repos = json_decode($response->getBody()->getContents(), true)['value'] ?? [];
            
            return array_map(function ($repo) {
                return [
                    'id' => $repo['id'],
                    'name' => $repo['name'],
                    'default_branch' => str_replace('refs/heads/', '', $repo['defaultBranch'] ?? '')
                ];
            }, $repos);

        } catch (\Exception $e) {
            $errorDetail = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorDetail = $e->getResponse()->getBody()->getContents();
            }
            return ['error' => "Error al obtener repositorios: {$errorDetail}"];
        }
    }

    public function getBranches($repositoryId)
    {
        try {
            
            $response = $this->client->get("git/repositories/{$repositoryId}/refs?filter=heads/&api-version=7.1");
            $refs = json_decode($response->getBody()->getContents(), true)['value'] ?? [];
            
            return array_map(function ($ref) {
                return [                    
                    'name' => str_replace('refs/heads/', '', $ref['name']),
                    'objectId' => $ref['objectId'] 
                ];
            }, $refs);

        } catch (\Exception $e) {
            $errorDetail = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorDetail = $e->getResponse()->getBody()->getContents();
            }
            return ['error' => "Error al obtener ramas: {$errorDetail}"];
        }
    }

    public function getAgentsStatus()
    {
        try {
            // 1. Usamos el orgClient apuntando directamente a distributedtask
            $poolsResponse = $this->orgClient->get("distributedtask/pools?api-version=7.1");
            $pools = json_decode($poolsResponse->getBody()->getContents(), true)['value'] ?? [];

            $allAgents = [];

            // 2. Iterar sobre los pools para traer los agentes
            foreach ($pools as $pool) {
                try {
                    // Usamos orgClient de nuevo
                    $agentsResponse = $this->orgClient->get("distributedtask/pools/{$pool['id']}/agents?includeAssignedRequest=true&api-version=7.1");
                    $agents = json_decode($agentsResponse->getBody()->getContents(), true)['value'] ?? [];

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
                } catch (\Exception $subException) {
                    // Ignoramos pools sin acceso y continuamos
                    continue; 
                }
            }

            return $allAgents;

        } catch (\Exception $e) {
            $urlAttempted = method_exists($e, 'getRequest') ? (string) $e->getRequest()->getUri() : 'URL desconocida';
            $errorDetail = $e->getMessage();
            
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorDetail = $e->getResponse()->getBody()->getContents();
            }
            
            return ['error' => "URL intentada: {$urlAttempted} | Error: {$errorDetail}"];
        }
    }


    /**
     * Obtiene los Pull Requests activos (bypassing completed/abandoned) de un repositorio
     */
    public function getActivePullRequests($repositoryId)
    {
        try {
            $response = $this->client->get("git/repositories/{$repositoryId}/pullrequests?searchCriteria.status=active&api-version=7.1");
            $prs = json_decode($response->getBody()->getContents(), true)['value'] ?? [];
            
            return array_map(function ($pr) {
                $projectGuid = $pr['repository']['project']['id'];
                $repoGuid = $pr['repository']['id'];
                $prId = $pr['pullRequestId'];

                // ESTÁNDAR OFICIAL DE MICROSOFT: Barras normales (/) y GUIDs
                $artifactId = "vstfs:///Git/PullRequestId/{$projectGuid}/{$repoGuid}/{$prId}";

                return [
                    'pullRequestId' => $prId,
                    'title' => $pr['title'],
                    'sourceRefName' => str_replace('refs/heads/', '', $pr['sourceRefName'] ?? ''),
                    'targetRefName' => str_replace('refs/heads/', '', $pr['targetRefName'] ?? ''),
                    'createdBy' => $pr['createdBy']['displayName'] ?? 'Desconocido',
                    'artifactId' => $artifactId
                ];
            }, $prs);

        } catch (\Exception $e) {
            // ... (resto del catch igual)
            return ['error' => 'Error al obtener Pull Requests'];
        }
    }

    /**
     * Enlaza un Pull Request existente al Work Item
     */
    public function linkPullRequestToWorkItem($workItemId, $artifactId)
    {
        try {
            $payload = [
                [
                    "op" => "add",
                    "path" => "/relations/-",
                    "value" => [
                        "rel" => "ArtifactLink",
                        "url" => $artifactId,
                        "attributes" => [
                            "name" => "Pull Request"
                        ]
                    ]
                ]
            ];

            $response = $this->client->patch("wit/workitems/{$workItemId}?api-version=7.1", [
                'headers' => ['Content-Type' => 'application/json-patch+json'],
                'json' => $payload
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (\Exception $e) {
            $errorDetail = $e->getMessage();
            
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorDetail = $e->getResponse()->getBody()->getContents();
                
                // ¡Mejora de UX! Si Azure dice que ya existe, lo tratamos como un éxito silencioso
                if (str_contains($errorDetail, 'Relation already exists')) {
                    return [
                        'message' => 'El Pull Request ya se encontraba enlazado a esta tarea.'.$errorDetail
                    ];
                }
            }
            
            return ['error' => "Fallo al enlazar el Pull Request: {$errorDetail}"];
        }
    }

}
