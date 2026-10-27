<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Azure DevOps
    |--------------------------------------------------------------------------
    |
    | `org` acepta el nombre de la organización o la URL completa
    | (https://dev.azure.com/mi-org). No hay PAT global: cada usuario inicia
    | sesión con su PAT personal, que se usa en sus peticiones y en sus jobs
    | (agente en lote y programado). Scopes necesarios: Work Items
    | (Read & Write), Code (Read), Build (Read), Agent Pools (Read) y
    | Project and Team (Read).
    |
    */

    'org' => env('AZURE_DEVOPS_ORG'),

    'project' => env('AZURE_DEVOPS_PROJECT'),

    'timeout' => (int) env('AZURE_DEVOPS_TIMEOUT', 30),

    // Correos con permiso para administrar el catálogo de alistamiento
    'admins' => array_values(array_filter(array_map(
        fn ($email) => strtolower(trim($email)),
        explode(',', (string) env('AUTH_ADMINS', '')),
    ))),

];
