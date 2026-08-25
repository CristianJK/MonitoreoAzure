<?php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
class AzureDevOpsService
{
    protected $client;
    protected $organization;
    protected $project;

    public function __construct()
    {
        $this->organization = env('AZURE_DEVOPS_ORGANIZATION');
        $this->project = env('AZURE_DEVOPS_PROJECT');
        
        $this->client = new Client([
            'base_uri' => "https://dev.azure.com/{$this->organization}/{$this->project}/_apis/",
            'auth' => ['', env('AZURE_DEVOPS_PAT')],
            'headers' => [                
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getActiveWorkItems()
    {
        try {
            $query = "Select [System.Id], [System.Title], [System.State] 
                      From WorkItems 
                      Where [System.State] <> 'Closed' 
                      Order By [System.ChangedDate] Desc";

            $response = $this->client->post('wit/wiql?api-version=7.1', [
                'json' => ['query' => $query]
            ]);

            $wiqlResult = json_decode($response->getBody()->getContents(), true);

            if (empty($wiqlResult['workItems'])) {
                return [];
            }

            $ids = array_column($wiqlResult['workItems'], 'id');
            return $this->getWorkItemsDetails($ids);

        } catch (RequestException $e) {
            Log::error('Error fetching work items: ' . $e->getMessage());
            return ['error' => 'No se pudieron obtener los Work Items'];
        }
    }

    private function getWorkItemsDetails(array $ids)
    {
        $idsString = implode(',', $ids);
        
        $response = $this->client->get("wit/workitems?ids={$idsString}&api-version=7.1");
        
        return json_decode($response->getBody()->getContents(), true)['value'] ?? [];
    }

}
