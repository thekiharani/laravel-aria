<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use NoriaLabs\Aria\AriaServiceProvider;
use NoriaLabs\Aria\Contracts\KnowledgeSource;
use NoriaLabs\Aria\Contracts\Persona;
use NoriaLabs\Aria\Tests\Fixtures\StubPersona;
use NoriaLabs\Aria\Tests\Fixtures\StubSource;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, AriaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Point ARIA_TEST_PG at a scratch Postgres to exercise jsonb, the
        // vector column and the foreign keys. Without it the suite runs on
        // SQLite and the Postgres-only assertions skip themselves.
        if (($url = env('ARIA_TEST_PG')) !== null) {
            $app['config']->set('database.connections.aria_pg', [
                'driver' => 'pgsql',
                'url' => $url,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
            $app['config']->set('database.default', 'aria_pg');
        } else {
            $app['config']->set('database.default', 'testing');
        }

        $app['config']->set('cache.default', 'array');
        $app['config']->set('ai.caching.embeddings.store', 'array');
        $app['config']->set('aria.pricing', [
            'cheap-model' => ['input' => 100_000, 'output' => 200_000],
            'dear-model' => ['input' => 900_000, 'output' => 3_000_000],
        ]);

        $app->bind(KnowledgeSource::class, StubSource::class);
        $app->bind(Persona::class, StubPersona::class);
    }
}
