<?php

declare(strict_types=1);

use Laravel\Ai\Embeddings;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Conversations\Assistant;
use NoriaLabs\Aria\Knowledge\KnowledgeDocument;
use NoriaLabs\Aria\Knowledge\KnowledgeIndex;
use NoriaLabs\Aria\Models\Document;
use NoriaLabs\Aria\Support\Unmetered;
use NoriaLabs\Aria\Tests\Fixtures\StubSource;

it('resolves the assistant once the host has bound a persona and a source', function (): void {
    expect(app(Assistant::class))->toBeInstanceOf(Assistant::class);
    expect(app(KnowledgeIndex::class))->toBeInstanceOf(KnowledgeIndex::class);
});

it('defaults an app with no tenancy to an uncapped, unscoped policy', function (): void {
    $policy = app(BudgetPolicy::class);

    expect($policy)->toBeInstanceOf(Unmetered::class);
    expect($policy->cap())->toBeNull();
    expect($policy->scope())->toBeNull();
});

it('prefixes every table so a host can keep them out of its own namespace', function (): void {
    expect((new Document)->getTable())->toBe('aria_documents');
});

it('rebuilds the index from the console', function (): void {
    Embeddings::fake();

    StubSource::$documents = [
        new KnowledgeDocument('page', 'a', 'Reconciliation', 'Matching payments.'),
    ];

    $this->artisan('aria:index')->assertSuccessful();

    expect(Document::query()->count())->toBe(1);
});

it('reads the provider and model from config', function (): void {
    config(['aria.provider' => 'gemini', 'aria.model' => 'flash']);

    expect(app(Assistant::class)->provider())->toBe(['gemini' => 'flash']);
});
