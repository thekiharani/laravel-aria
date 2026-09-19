<?php

declare(strict_types=1);

namespace NoriaLabs\Aria;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use NoriaLabs\Aria\Console\IndexCommand;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Contracts\KnowledgeSource;
use NoriaLabs\Aria\Contracts\Persona;
use NoriaLabs\Aria\Conversations\Assistant;
use NoriaLabs\Aria\Knowledge\KnowledgeIndex;
use NoriaLabs\Aria\Spend\Budget;
use NoriaLabs\Aria\Spend\RecordRun;
use NoriaLabs\Aria\Support\Unmetered;

class AriaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aria.php', 'aria');

        // The host app binds KnowledgeSource and Persona. Nothing is bound for
        // them here: a default persona is one every product would have to
        // remember to replace, and forgetting is silent.
        $this->app->bind(BudgetPolicy::class, Unmetered::class);

        $this->app->singleton(Budget::class);
        $this->app->singleton(KnowledgeIndex::class);

        $this->app->bind(Assistant::class, fn ($app) => new Assistant(
            $app->make(Persona::class),
            $app->make(Budget::class),
            $app->tagged('aria.tools'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([IndexCommand::class]);

            $this->publishes([
                __DIR__.'/../config/aria.php' => config_path('aria.php'),
            ], 'aria-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'aria-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('aria.record_runs', true)) {
            Event::listen(AgentPrompted::class, [RecordRun::class, 'prompted']);
            Event::listen(AgentFailed::class, [RecordRun::class, 'failed']);
            Event::listen(EmbeddingsGenerated::class, [RecordRun::class, 'embedded']);
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [Assistant::class, KnowledgeIndex::class, Budget::class, BudgetPolicy::class];
    }
}
