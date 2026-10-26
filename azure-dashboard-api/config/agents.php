<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agente de comentario diario
    |--------------------------------------------------------------------------
    |
    | `comment_generator`: copilot_studio (Direct Line) o template (sin IA).
    | Si Copilot Studio falla o no está configurado se usa la plantilla como
    | respaldo y la propuesta queda marcada como "generada sin IA".
    |
    */

    'comment_generator' => env('AGENT_COMMENT_GENERATOR', 'copilot_studio'),

    'timezone' => env('AGENT_TIMEZONE', 'America/Bogota'),

    'schedule_time' => env('AGENT_SCHEDULE_TIME', '17:30'),

    'schedule_enabled' => (bool) env('AGENT_SCHEDULE_ENABLED', true),

    'max_commits' => 20,

    'max_files_per_commit' => 15,

    'copilot_studio' => [
        'directline_url' => env('COPILOT_DIRECTLINE_URL', 'https://directline.botframework.com/v3/directline'),
        'directline_secret' => env('COPILOT_DIRECTLINE_SECRET'),
        'timeout' => (int) env('COPILOT_TIMEOUT', 30),
    ],

];
