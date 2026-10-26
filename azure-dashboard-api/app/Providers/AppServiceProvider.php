<?php

namespace App\Providers;

use App\Services\Agents\CommentGenerator;
use App\Services\Agents\CopilotStudioCommentGenerator;
use App\Services\Agents\DirectLineClient;
use App\Services\Agents\TemplateCommentGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        //
    }
}
