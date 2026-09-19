<?php

declare(strict_types=1);

namespace NoriaLabs\Aria;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use NoriaLabs\Aria\Console\IndexCommand;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Contracts\KnowledgeSource;
use NoriaLabs\Aria\Contracts\Normaliser;
use NoriaLabs\Aria\Contracts\Persona;
use NoriaLabs\Aria\Conversations\AriaConversationStore;
use NoriaLabs\Aria\Conversations\Assistant;
use NoriaLabs\Aria\Knowledge\KnowledgeIndex;
use NoriaLabs\Aria\Spend\Budget;
use NoriaLabs\Aria\Spend\RecordRun;
use NoriaLabs\Aria\Support\Masker;
use NoriaLabs\Aria\Support\PlainText;
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

        // A class name rather than a callable in config, because config:cache
        // var_exports the array and throws on a closure - a callable would
        // pass every test and break the first deploy.
        $this->app->bind(Normaliser::class, function ($app) {
            $normaliser = config('aria.normaliser');

            return is_string($normaliser) && $normaliser !== ''
                ? $app->make($normaliser)
                : $app->make(PlainText::class);
        });

        // The SDK's store, pointed at Aria's tables. Rebinding it here rather
        // than reimplementing the contract keeps the tool-turn and approval
        // replay the SDK already does correctly.
        $this->app->singleton(ConversationStore::class, AriaConversationStore::class);

        $this->app->singleton(Budget::class);
        $this->app->singleton(KnowledgeIndex::class);
        $this->app->singleton(Masker::class);

        $this->app->bind(Assistant::class, fn ($app) => new Assistant(
            $app->make(Persona::class),
            $app->make(Budget::class),
            $app->make(Masker::class),
            $app->make(Normaliser::class),
            $app->make(ConversationStore::class),
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

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'aria-migrations');
        }

        // Loaded from the package unless the host published them. Doing both
        // runs every table twice, which fails on the second CREATE and leaves
        // a half-migrated database behind.
        if (config('aria.load_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('aria.record_runs', true)) {
            // AgentStreamed extends AgentPrompted but is listened for in its
            // own right: the dispatcher walks interfaces, never parents, so a
            // listener on the parent alone leaves every streamed turn unbilled.
            Event::listen(AgentPrompted::class, [RecordRun::class, 'prompted']);
            Event::listen(AgentStreamed::class, [RecordRun::class, 'prompted']);
            Event::listen(AgentFailed::class, [RecordRun::class, 'failed']);
            Event::listen(EmbeddingsGenerated::class, [RecordRun::class, 'embedded']);
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [Assistant::class, KnowledgeIndex::class, Budget::class, BudgetPolicy::class, ConversationStore::class];
    }
}
