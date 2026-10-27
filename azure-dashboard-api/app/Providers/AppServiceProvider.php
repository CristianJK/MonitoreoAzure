<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Agents\CommentGenerator;
use App\Services\Agents\CopilotStudioCommentGenerator;
use App\Services\Agents\DirectLineClient;
use App\Services\Agents\TemplateCommentGenerator;
use App\Services\AzureDevOpsService;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PAT del usuario de la sesión (o de User::actAs() en los jobs)
        $this->app->bind(AzureDevOpsService::class, function ($app) {
            $service = new AzureDevOpsService;
            $user = $app['auth']->guard('web')->user();

            return $user?->azure_pat ? $service->forUser($user) : $service;
        });

        $this->app->bind(CommentGenerator::class, function () {
            if (config('agents.comment_generator') === 'template') {
                return new TemplateCommentGenerator;
            }

            $config = config('agents.copilot_studio');

            return new CopilotStudioCommentGenerator(
                $config['directline_secret']
                    ? new DirectLineClient($config['directline_url'], $config['directline_secret'], $config['timeout'])
                    : null,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-checklist', fn (User $user) => $user->isAdmin()
            ? Response::allow()
            : Response::deny('Solo los administradores pueden modificar el catálogo de alistamiento.'));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('agent-batch', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
    }
}
