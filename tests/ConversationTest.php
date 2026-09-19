<?php

declare(strict_types=1);

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;
use NoriaLabs\Aria\Agents\AriaAgent;
use NoriaLabs\Aria\Contracts\Normaliser;
use NoriaLabs\Aria\Conversations\Assistant;
use NoriaLabs\Aria\Models\Conversation;
use NoriaLabs\Aria\Models\Message;
use NoriaLabs\Aria\Tests\Fixtures\Shouty;
use NoriaLabs\Aria\Tests\Fixtures\StubPersona;
use NoriaLabs\Aria\Tools\KnowledgeSearch;

describe('the agent', function (): void {
    it('takes its instructions from the persona and nothing from the package', function (): void {
        expect((new AriaAgent(new StubPersona))->instructions())->toBe('You are a test assistant.');
    });

    it('always offers retrieval, plus whatever the product added', function (): void {
        $extra = new class
        {
            public function description(): string
            {
                return 'a product tool';
            }
        };

        $tools = iterator_to_array((new AriaAgent(new StubPersona, [$extra]))->tools());

        expect($tools)->toHaveCount(2);
        expect($tools[0])->toBeInstanceOf(KnowledgeSearch::class);
    });

    /*
     * Read through a method rather than a #[MaxSteps] attribute, because an
     * attribute cannot read config and the SDK prefers a method over one.
     */
    it('takes its ceilings from config, so two products can differ without two agent classes', function (): void {
        config(['aria.limits.max_steps' => 3, 'aria.limits.max_tokens' => 400, 'aria.limits.timeout' => 9]);

        $agent = new AriaAgent(new StubPersona);

        expect($agent->maxSteps())->toBe(3);
        expect($agent->maxTokens())->toBe(400);
        expect($agent->timeout())->toBe(9);
    });
});

describe('running a turn', function (): void {
    it('answers, and keeps both sides of the exchange', function (): void {
        AriaAgent::fake(['Yes, we reconcile mobile money.']);

        $response = app(Assistant::class)->reply('Do you do reconciliation?');

        expect($response->text)->toBe('Yes, we reconcile mobile money.');
        expect(Conversation::query()->count())->toBe(1);
        expect(Message::query()->pluck('role')->all())->toBe(['user', 'assistant']);
    });

    /*
     * The SDK only remembers a turn that already has a conversation or a
     * participant, and a website visitor has neither - so the row has to
     * exist before the prompt, or an anonymous exchange is never stored.
     */
    it('remembers an anonymous visitor, who has no participant to be keyed by', function (): void {
        AriaAgent::fake(['Hello.']);

        $response = app(Assistant::class)->reply('hello');

        expect($response->conversationId)->not->toBeNull();
        expect(Message::query()->where('role', 'user')->value('content'))->toBe('hello');
    });

    it('carries an earlier turn back into the next one as history', function (): void {
        AriaAgent::fake(['First.', 'Second.']);

        $assistant = app(Assistant::class);

        $first = $assistant->reply('what do you build?');
        $assistant->reply('and how long does it take?', $first->conversationId);

        expect(Message::query()->count())->toBe(4);
        expect(Conversation::query()->count())->toBe(1);
    });

    it('titles a new thread from the opening message', function (): void {
        AriaAgent::fake(['Sure.']);

        app(Assistant::class)->reply('Do you integrate with our bank?');

        expect(Conversation::query()->value('title'))->toContain('Do you integrate');
    });

    it('stamps the corpus on a thread, so two products sharing a database never read each other', function (): void {
        AriaAgent::fake(['Sure.']);

        app(Assistant::class)->reply('hello');

        expect(Conversation::query()->value('corpus'))->toBe('stub');
    });
});

describe('redacting and normalising', function (): void {
    /*
     * Masked at the Assistant boundary rather than in SDK prompt middleware.
     * Middleware rewrites the prompt on the way to the provider, but the
     * store writes the prompt it was handed, so the raw address would be
     * kept and replayed as history on the next turn.
     */
    it('redacts an email address before it is stored or sent', function (): void {
        AriaAgent::fake(['Noted.']);

        app(Assistant::class)->reply('write to joseph@noria.co.ke today');

        $stored = (string) Message::query()->where('role', 'user')->value('content');

        expect($stored)->toContain('[EMAIL REDACTED]');
        expect($stored)->not->toContain('joseph@noria.co.ke');
    });

    it('redacts something shaped like a card number', function (): void {
        AriaAgent::fake(['Noted.']);

        app(Assistant::class)->reply('card 4111 1111 1111 1111 please');

        expect((string) Message::query()->where('role', 'user')->value('content'))
            ->toContain('[CARD REDACTED]')
            ->not->toContain('4111');
    });

    it('leaves ordinary text alone', function (): void {
        AriaAgent::fake(['Noted.']);

        app(Assistant::class)->reply('reconcile the month end');

        expect(Message::query()->where('role', 'user')->value('content'))->toBe('reconcile the month end');
    });

    it('does nothing when the host turned masking off', function (): void {
        config(['aria.masking.enabled' => false]);
        AriaAgent::fake(['Noted.']);

        app(Assistant::class)->reply('joseph@noria.co.ke');

        expect(Message::query()->where('role', 'user')->value('content'))->toBe('joseph@noria.co.ke');
    });

    /*
     * A class name and not a callable, because config:cache var_exports the
     * config array and throws on a closure - a callable would pass every test
     * here and break the first deploy that cached its config.
     */
    it('passes the answer through the normaliser the host named in config', function (): void {
        config(['aria.normaliser' => Shouty::class]);
        AriaAgent::fake(['quietly now']);

        expect(app(Assistant::class)->reply('hello')->text)->toBe('QUIETLY NOW');
    });

    it('survives config caching, which a closure in config would not', function (): void {
        config(['aria.normaliser' => Shouty::class]);

        expect(var_export(config('aria'), true))->toBeString();
        expect(app(Normaliser::class))->toBeInstanceOf(Shouty::class);
    });

    it('leaves the text alone when the host named no normaliser', function (): void {
        AriaAgent::fake(['quietly now']);

        expect(app(Assistant::class)->reply('hello')->text)->toBe('quietly now');
    });
});

describe('the conversation store', function (): void {
    it('is the SDK store, pointed at Aria tables rather than rewritten', function (): void {
        expect(app(ConversationStore::class))
            ->toBeInstanceOf(DatabaseConversationStore::class);
    });
});
