<?php

use App\Jobs\DispatchDailyComments;
use App\Services\Agents\AgentCommentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('agents:daily-comments {--assigned=@Me}', function (AgentCommentService $agent) {
    $result = $agent->startBatch($this->option('assigned'), 'scheduled');

    if (isset($result['error'])) {
        $this->error($result['error']);

        return 1;
    }

    $this->info("Lote {$result['batch_id']}: {$result['count']} work items en cola. Las propuestas quedan pendientes de revisión.");
})->purpose('Genera las propuestas de comentario diario (requiere queue:work)');

// Requiere el cron de `schedule:run` y un worker de colas (`queue:work`)
if (config('agents.schedule_enabled')) {
    Schedule::job(new DispatchDailyComments('@Me', 'scheduled'))
        ->weekdays()
        ->at(config('agents.schedule_time'))
        ->timezone(config('agents.timezone'));
}
