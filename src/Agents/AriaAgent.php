<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Agents;

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Promptable;
use NoriaLabs\Aria\Contracts\Persona;
use NoriaLabs\Aria\Tools\KnowledgeSearch;

/**
 * One agent for every product. What differs between them is the persona and
 * the tools, both injected, so there is no per-product subclass.
 *
 * History, persistence and approval resume come from the SDK's trait rather
 * than from this package. The hand-rolled version replayed user and assistant
 * text only: a tool call was persisted and then never shown to the model
 * again, so the assistant re-ran the same retrieval on every single turn.
 *
 * The limits are methods rather than #[MaxSteps] attributes because the SDK
 * prefers a method over an attribute, and an attribute cannot read config -
 * a support assistant and a research one want different ceilings without
 * needing different agent classes.
 */
class AriaAgent implements Agent, HasTools, RemembersConversations
{
    use Promptable;
    use RemembersConversationsTrait;

    /** @param iterable<int, object> $extraTools */
    public function __construct(
        private Persona $persona,
        private iterable $extraTools = [],
    ) {}

    public function instructions(): string
    {
        return $this->persona->instructions();
    }

    /** @return iterable<int, object> */
    public function tools(): iterable
    {
        yield app(KnowledgeSearch::class);

        // Yielded one at a time rather than `yield from`: that preserves the
        // source array's keys, so a product's first tool reuses key 0 and
        // silently replaces retrieval when the generator is collected.
        foreach ($this->extraTools as $tool) {
            yield $tool;
        }
    }

    public function maxSteps(): ?int
    {
        return Config::integer('aria.limits.max_steps', 6);
    }

    public function maxTokens(): ?int
    {
        return Config::integer('aria.limits.max_tokens', 1500);
    }

    public function timeout(): int
    {
        return Config::integer('aria.limits.timeout', 60);
    }

    /** How many stored turns are replayed. Each one is tokens on every turn. */
    protected function maxConversationMessages(): int
    {
        return Config::integer('aria.history', 20);
    }
}
