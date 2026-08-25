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
        $this->organizationUrl = rtrim(env('AZURE_DEVOPS_ORG'), '/');
        $this->project = rawurlencode(env('AZURE_DEVOPS_PROJECT'));
        $pat = env('AZURE_DEVOPS_PAT');

        // Cliente para nivel de PROYECTO (Work Items)
        $this->client = new Client([
            'base_uri' => "{$this->organizationUrl}/{$this->project}/_apis/",
            'auth'     => ['', $pat],
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        // Cliente para nivel de ORGANIZACIÓN (Agentes, Pools)
        $this->orgClient = new Client([
            'base_uri' => "{$this->organizationUrl}/_apis/", // <-- Ruta limpia sin el proyecto
            'auth'     => ['', $pat],
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getActiveWorkItems($limit = 100, $assignedTo = '@Me')
    {
        try {
            
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

            $query .= " Order By [System.ChangedDate] Desc";

            $response = $this->client->post("wit/wiql?\$top={$limit}&api-version=7.1", [
                'json' => ['query' => $query]
            ]);

            $wiqlResult = json_decode($response->getBody()->getContents(), true);

            if (empty($wiqlResult['workItems'])) {
                return [];
            }

            $ids = array_column($wiqlResult['workItems'], 'id');
            return $this->getWorkItemsDetails($ids);

        } catch (\Exception $e) {
            $urlAttempted = method_exists($e, 'getRequest') ? (string) $e->getRequest()->getUri() : 'URL desconocida';
            $errorDetail = $e->getMessage();
            
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $errorDetail = $e->getResponse()->getBody()->getContents();
            }
            
            return ['error' => "URL intentada: {$urlAttempted} | Error: {$errorDetail}"];
        }
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

}
