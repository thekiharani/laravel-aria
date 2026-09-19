<?php

declare(strict_types=1);

use Laravel\Ai\Embeddings;
use NoriaLabs\Aria\Knowledge\KnowledgeDocument;
use NoriaLabs\Aria\Knowledge\KnowledgeIndex;
use NoriaLabs\Aria\Models\Chunk;
use NoriaLabs\Aria\Models\Document;
use NoriaLabs\Aria\Support\Vectors;
use NoriaLabs\Aria\Tests\Fixtures\StubSource;

function doc(string $key, string $title, string $body): KnowledgeDocument
{
    return new KnowledgeDocument(
        sourceType: 'page',
        sourceKey: $key,
        title: $title,
        body: $body,
        sourceUrl: '/'.$key,
    );
}

beforeEach(function (): void {
    StubSource::$documents = [];

    // No provider is called: every chunk gets a stub vector, so these tests
    // exercise the index rather than somebody's embedding model.
    Embeddings::fake();
});

/*
 * Retrieval behaviour splits by driver. Writing, hashing and de-duplicating
 * are the same everywhere; matching is not, and a stub vector carries no
 * meaning to compare against, so the similarity path cannot be asserted
 * without a real provider. These cover the LIKE fallback and say so.
 */
function skipWhereVectorsExist(): void
{
    if (Vectors::indexed()) {
        test()->markTestSkipped('Similarity search needs a real embedding provider to assert.');
    }
}

it('writes a document per entry in the source', function (): void {
    StubSource::$documents = [
        doc('one', 'Reconciliation', 'Matching payments to invoices at month end.'),
        doc('two', 'Discovery', 'A week that produces a fixed price.'),
    ];

    $result = app(KnowledgeIndex::class)->rebuild();

    expect($result['documents'])->toBe(2);
    expect(Document::query()->count())->toBe(2);
});

it('re-embeds only the documents whose content moved', function (): void {
    StubSource::$documents = [doc('one', 'Reconciliation', 'First body.')];
    app(KnowledgeIndex::class)->rebuild();

    $first = Document::query()->firstOrFail()->source_hash;

    // Same content again: the hash holds, so nothing is re-embedded.
    $second = app(KnowledgeIndex::class)->rebuild();
    expect($second['chunks'])->toBe(0);

    StubSource::$documents = [doc('one', 'Reconciliation', 'A different body entirely.')];
    app(KnowledgeIndex::class)->rebuild();

    expect(Document::query()->firstOrFail()->source_hash)->not->toBe($first);
});

it('updates a document in place rather than duplicating it', function (): void {
    StubSource::$documents = [doc('one', 'Old title', 'Body.')];
    app(KnowledgeIndex::class)->rebuild();

    StubSource::$documents = [doc('one', 'New title', 'Body.')];
    app(KnowledgeIndex::class)->rebuild();

    expect(Document::query()->count())->toBe(1);
    expect(Document::query()->firstOrFail()->title)->toBe('New title');
});

it('finds a document by its words when there is no vector column', function (): void {
    skipWhereVectorsExist();

    StubSource::$documents = [
        doc('one', 'Reconciliation', 'Matching payments to invoices at month end.'),
        doc('two', 'Discovery', 'A week that produces a fixed price.'),
    ];
    app(KnowledgeIndex::class)->rebuild();

    $results = app(KnowledgeIndex::class)->search('invoices');

    expect($results)->toHaveCount(1);
    expect($results[0]['title'])->toBe('Reconciliation');
    expect($results[0]['url'])->toBe('/one');
});

it('returns nothing for an empty query rather than everything', function (): void {
    StubSource::$documents = [doc('one', 'Reconciliation', 'Body.')];
    app(KnowledgeIndex::class)->rebuild();

    expect(app(KnowledgeIndex::class)->search('   '))->toBe([]);
});

it('never returns one document twice, however many chunks matched', function (): void {
    skipWhereVectorsExist();

    StubSource::$documents = [doc('one', 'Long', str_repeat('payments ', 900))];
    app(KnowledgeIndex::class)->rebuild();

    expect(Chunk::query()->count())->toBeGreaterThan(1);
    expect(app(KnowledgeIndex::class)->search('payments'))->toHaveCount(1);
});

it('reads only its own corpus, so two products can share a database', function (): void {
    skipWhereVectorsExist();

    StubSource::$documents = [doc('mine', 'Mine', 'A reconciliation document.')];
    app(KnowledgeIndex::class)->rebuild();

    // A second product's document, in the same tables.
    $other = Document::query()->create([
        'corpus' => 'somebody-else',
        'source_type' => 'page',
        'source_key' => 'theirs',
        'title' => 'Theirs',
        'source_hash' => 'x',
    ]);
    $other->chunks()->create([
        'content' => 'A reconciliation document.',
        'content_hash' => 'x',
        'embedding_provider' => 'openai',
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dimensions' => 1536,
    ]);

    $results = app(KnowledgeIndex::class)->search('reconciliation');

    expect($results)->toHaveCount(1);
    expect($results[0]['title'])->toBe('Mine');
});
