<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Azure DevOps
    |--------------------------------------------------------------------------
    |
    | `org` acepta el nombre de la organización o la URL completa
    | (https://dev.azure.com/mi-org). El PAT necesita los scopes: Work Items
    | (Read & Write), Code (Read), Build (Read), Agent Pools (Read) y
    | Project and Team (Read).
    |
    */

    'org' => env('AZURE_DEVOPS_ORG'),

    'project' => env('AZURE_DEVOPS_PROJECT'),

    'pat' => env('AZURE_DEVOPS_PAT'),

    'timeout' => (int) env('AZURE_DEVOPS_TIMEOUT', 30),

];
