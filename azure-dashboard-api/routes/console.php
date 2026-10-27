<?php

use App\Jobs\DispatchDailyComments;
use App\Models\User;
use App\Services\Agents\AgentCommentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('agents:daily-comments {email}', function () {
    $user = User::where('email', strtolower($this->argument('email')))->first();

    if (! $user?->azure_pat) {
        $this->error('El usuario no existe o no tiene un PAT guardado: debe iniciar sesión en el dashboard.');

        return 1;
    }

    $result = $user->actAs(fn () => app(AgentCommentService::class)->startBatch($user, 'scheduled'));

    if (isset($result['error'])) {
        $this->error($result['error']);

        return 1;
    }

    $this->info("Lote {$result['batch_id']}: {$result['count']} work items en cola. Las propuestas quedan pendientes de revisión.");
})->purpose('Genera las propuestas de comentario diario de un usuario con su PAT (requiere queue:work)');

Artisan::command('users:revoke {email}', function () {
    $user = User::where('email', strtolower($this->argument('email')))->first();

    if (! $user) {
        $this->error('El usuario no existe.');

        return 1;
    }

    $user->update(['azure_pat' => null, 'agent_schedule_enabled' => false]);
    $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

    $this->info("Acceso revocado para {$user->email} ({$sessions} sesión(es) cerrada(s)). Revoca también su PAT en Azure DevOps.");
})->purpose('Cierra las sesiones de un usuario y borra su PAT guardado');

// Requiere el cron de `schedule:run` y un worker de colas (`queue:work`).
// Solo corre para los usuarios que activaron la ejecución programada.
if (config('agents.schedule_enabled')) {
    Schedule::job(new DispatchDailyComments)
        ->weekdays()
        ->at(config('agents.schedule_time'))
        ->timezone(config('agents.timezone'));
}
