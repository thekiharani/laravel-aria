<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use NoriaLabs\Aria\Contracts\Persona;
use NoriaLabs\Aria\Enums\Role;
use NoriaLabs\Aria\Models\Conversation;
use NoriaLabs\Aria\Tools\KnowledgeSearch;

/**
 * One agent for every product. What differs between them is the persona and
 * the tools, both injected, so there is no per-product subclass to keep in
 * step with this one.
 */
#[MaxSteps(6)]
#[MaxTokens(1500)]
#[Timeout(60)]
class AriaAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @param iterable<int, object> $extraTools */
    public function __construct(
        public Conversation $conversation,
        private Persona $persona,
        private iterable $extraTools = [],
    ) {}

    public function instructions(): string
    {
        return $this->persona->instructions();
    }

    /** @return iterable<int, object> */
    public function messages(): iterable
    {
        $history = $this->conversation->messages()
            ->where('role', '!=', Role::System->value)
            ->get();

        // The trailing visitor turn is the prompt, not history: replaying it
        // here sends it twice and the model answers its own echo.
        $last = $history->last();

        if ($last !== null && $last->role === Role::User) {
            $history = $history->slice(0, -1);
        }

        foreach ($history as $message) {
            yield $message->role === Role::User
                ? new UserMessage($message->content)
                : new AssistantMessage($message->content);
        }
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
}
