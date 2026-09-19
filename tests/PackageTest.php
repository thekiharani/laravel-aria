<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Embeddings;
use NoriaLabs\Aria\Aria;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Conversations\Assistant;
use NoriaLabs\Aria\Knowledge\KnowledgeDocument;
use NoriaLabs\Aria\Knowledge\KnowledgeIndex;
use NoriaLabs\Aria\Models\Chunk;
use NoriaLabs\Aria\Models\Conversation;
use NoriaLabs\Aria\Models\Document;
use NoriaLabs\Aria\Support\Unmetered;
use NoriaLabs\Aria\Tests\Fixtures\HostConversation;
use NoriaLabs\Aria\Tests\Fixtures\StubSource;

describe('wiring', function (): void {
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
});

describe('naming the tables', function (): void {
    it('prefixes every table so a host can keep them out of its own namespace', function (): void {
        expect((new Document)->getTable())->toBe('aria_documents');
    });

    it('renames the prefix across every table at once', function (): void {
        config(['aria.table_prefix' => 'noria_ai_']);

        expect((new Conversation)->getTable())->toBe('noria_ai_conversations');
        expect((new Chunk)->getTable())->toBe('noria_ai_chunks');
    });

    it('renames one table without spelling out the other five', function (): void {
        config(['aria.tables.conversations' => 'support_threads']);

        expect((new Conversation)->getTable())->toBe('support_threads');
        expect((new Chunk)->getTable())->toBe('aria_chunks');
    });

    /*
     * The migration and the models read the same resolver, so a host that
     * renames a table cannot end up with models querying one name and a
     * migration having created another.
     */
    it('creates the tables under the names the models will look for', function (): void {
        foreach (['conversations', 'messages', 'documents', 'chunks', 'runs', 'spend_ledger'] as $name) {
            expect(Schema::hasTable(Aria::table($name)))->toBeTrue("missing {$name}");
        }
    });
});

describe('swapping the models', function (): void {
    afterEach(fn () => Aria::forgetModels());

    /*
     * A host that cannot add a relation, a scope or a trait to a package's
     * model forks the package. These setters are why it does not have to.
     */
    it('reads through the model the host substituted', function (): void {
        Aria::useConversationModel(HostConversation::class);

        expect(Aria::conversationModel())->toBe(HostConversation::class);
    });

    it('keeps the substituted model on the same table', function (): void {
        Aria::useConversationModel(HostConversation::class);

        expect((new HostConversation)->getTable())->toBe('aria_conversations');
    });

    it('refuses a substitute that is not the model it replaces', function (): void {
        Aria::useConversationModel(stdClass::class);
    })->throws(InvalidArgumentException::class);

    it('follows relations through the substituted model', function (): void {
        Aria::useConversationModel(HostConversation::class);

        $conversation = HostConversation::query()->create(['corpus' => 'stub', 'title' => 'a thread']);
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'hello',
            'agent' => 'test',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);

        expect($conversation->messages()->count())->toBe(1);
        expect($conversation->messages()->first()->conversation)->toBeInstanceOf(HostConversation::class);
    });
});
