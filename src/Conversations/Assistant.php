<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Conversations;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use NoriaLabs\Aria\Agents\AriaAgent;
use NoriaLabs\Aria\Contracts\Normaliser;
use NoriaLabs\Aria\Contracts\Persona;
use NoriaLabs\Aria\Exceptions\BudgetExhausted;
use NoriaLabs\Aria\Spend\Budget;
use NoriaLabs\Aria\Support\Masker;

/**
 * Runs a turn under a budget.
 *
 * Persisting the turn, replaying history and resuming an approval pause are
 * the SDK's, through RemembersConversations on the agent. What is left here
 * is what the SDK does not do: masking before the text is stored or sent,
 * reserving against a cap before the call, and giving the reservation back
 * after it whatever happened.
 */
class Assistant
{
    /** @param iterable<int, object> $tools */
    public function __construct(
        private Persona $persona,
        private Budget $budget,
        private Masker $masker,
        private Normaliser $normaliser,
        private ConversationStore $store,
        private iterable $tools = [],
    ) {}

    public function reply(string $message, ?string $conversationId = null, ?object $participant = null): AgentResponse
    {
        $message = $this->masker->mask(trim($message));

        $agent = $this->agent($message, $conversationId, $participant);

        $reserved = $this->reserve($message);

        try {
            $response = $agent->prompt($message, provider: $this->provider());
        } finally {
            $this->budget->refund($reserved);
        }

        $response->text = $this->normaliser->normalise($response->text);

        return $response;
    }

    /**
     * The SDK's own streamable response: iterable, Responsable, and able to
     * speak the Vercel protocol, so a controller can return it for SSE and a
     * Livewire island can walk it - without this package owning a transport.
     *
     * @param  (callable(string): void)|null  $onDelta
     */
    public function stream(string $message, ?string $conversationId = null, ?object $participant = null, ?callable $onDelta = null): StreamableAgentResponse
    {
        $message = $this->masker->mask(trim($message));

        $agent = $this->agent($message, $conversationId, $participant);

        $reserved = $this->reserve($message);

        $response = $agent->stream($message, provider: $this->provider());

        // Refunded when the stream completes. A stream the caller abandons
        // never completes and never gives the reservation back until the
        // period rolls, which makes the cap stricter rather than looser.
        $response->then(fn () => $this->budget->refund($reserved));

        if ($onDelta === null) {
            return $response;
        }

        return $response->each(function (object $event) use ($onDelta): void {
            if ($event instanceof TextDelta) {
                $delta = $this->normaliser->normalise($event->delta);

                if ($delta !== '') {
                    $onDelta($delta);
                }
            }
        });
    }

    /**
     * The provider and model to prompt with, e.g. ['openai' => 'gpt-5.6-luna'].
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return [
            Config::string('aria.provider', 'openai') => Config::string('aria.model', ''),
        ];
    }

    /**
     * An anonymous visitor's first turn needs a row before the SDK will keep
     * anything: its middleware only remembers a turn that already has a
     * conversation or a participant, and a website visitor has neither.
     */
    private function agent(string $message, ?string $conversationId, ?object $participant): AriaAgent
    {
        $agent = new AriaAgent($this->persona, $this->tools);

        if ($conversationId !== null) {
            return $agent->continue($conversationId, $participant);
        }

        if ($participant !== null) {
            return $agent->forParticipant($participant);
        }

        return $agent->continue(
            $this->store->storeConversation(null, null, Str::limit($message, 50, preserveWords: true)),
        );
    }

    /**
     * Held before the call, given back after. A reservation that is never
     * refunded double-counts; one that is never taken is a cap that a hundred
     * simultaneous callers all walk through together.
     */
    private function reserve(string $prompt): int
    {
        $estimate = $this->budget->estimateFor($prompt);

        if (! $this->budget->reserve($estimate)) {
            throw BudgetExhausted::forPeriod();
        }

        return $estimate;
    }
}
