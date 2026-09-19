<?php

declare(strict_types=1);

use NoriaLabs\Aria\Agents\AriaAgent;
use NoriaLabs\Aria\Enums\Role;
use NoriaLabs\Aria\Models\Conversation;
use NoriaLabs\Aria\Tests\Fixtures\StubPersona;
use NoriaLabs\Aria\Tools\KnowledgeSearch;

function conversation(): Conversation
{
    return Conversation::query()->create(['corpus' => 'stub']);
}

it('treats the trailing visitor turn as the prompt', function (): void {
    $c = conversation();
    $c->messages()->create(['role' => Role::User, 'content' => 'first']);
    $c->messages()->create(['role' => Role::Assistant, 'content' => 'reply']);
    $c->messages()->create(['role' => Role::User, 'content' => 'the pending one']);

    expect($c->pendingPrompt())->toBe('the pending one');
});

it('has no pending prompt when the assistant spoke last', function (): void {
    $c = conversation();
    $c->messages()->create(['role' => Role::User, 'content' => 'hello']);
    $c->messages()->create(['role' => Role::Assistant, 'content' => 'hi']);

    expect($c->pendingPrompt())->toBe('');
});

it('does not replay the pending prompt as history, or the model answers its own echo', function (): void {
    $c = conversation();
    $c->messages()->create(['role' => Role::User, 'content' => 'first']);
    $c->messages()->create(['role' => Role::Assistant, 'content' => 'reply']);
    $c->messages()->create(['role' => Role::User, 'content' => 'the pending one']);

    $agent = new AriaAgent($c, new StubPersona);
    $history = collect(iterator_to_array($agent->messages()));

    expect($history)->toHaveCount(2);
});

it('sends the whole exchange as history when nothing is pending', function (): void {
    $c = conversation();
    $c->messages()->create(['role' => Role::User, 'content' => 'first']);
    $c->messages()->create(['role' => Role::Assistant, 'content' => 'reply']);

    $agent = new AriaAgent($c, new StubPersona);

    expect(iterator_to_array($agent->messages()))->toHaveCount(2);
});

it('takes its instructions from the persona and nothing from the package', function (): void {
    $agent = new AriaAgent(conversation(), new StubPersona);

    expect($agent->instructions())->toBe('You are a test assistant.');
});

it('always offers retrieval, plus whatever the product added', function (): void {
    $extra = new class
    {
        public function description(): string
        {
            return 'a product tool';
        }
    };

    $agent = new AriaAgent(conversation(), new StubPersona, [$extra]);
    $tools = iterator_to_array($agent->tools());

    expect($tools)->toHaveCount(2);
    expect($tools[0])->toBeInstanceOf(KnowledgeSearch::class);
});

it('orders messages by when they were written, not by id', function (): void {
    $c = conversation();
    $c->messages()->create(['role' => Role::User, 'content' => 'one']);
    $c->messages()->create(['role' => Role::Assistant, 'content' => 'two']);
    $c->messages()->create(['role' => Role::User, 'content' => 'three']);

    expect($c->messages()->pluck('content')->all())->toBe(['one', 'two', 'three']);
});
