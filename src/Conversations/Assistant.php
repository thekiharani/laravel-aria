<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Conversations;

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use NoriaLabs\Aria\Agents\AriaAgent;
use NoriaLabs\Aria\Contracts\Persona;
use NoriaLabs\Aria\Enums\Role;
use NoriaLabs\Aria\Exceptions\BudgetExhausted;
use NoriaLabs\Aria\Models\Conversation;
use NoriaLabs\Aria\Models\Message;
use NoriaLabs\Aria\Spend\Budget;

/**
 * Runs a turn and writes it down.
 *
 * streamReply takes a callable rather than owning a transport, which is why the
 * same runner serves a Livewire island, an SSE controller and a queued job
 * without knowing which it is talking to.
 */
class Assistant
{
    /** @param iterable<int, object> $tools */
    public function __construct(
        private Persona $persona,
        private Budget $budget,
        private iterable $tools = [],
    ) {}

    public function reply(Conversation $conversation, string $message): Message
    {
        $message = trim($message);

        $this->record($conversation, Role::User, $message);

        $reserved = $this->reserve($message);

        try {
            $response = $this->agent($conversation)->prompt($message, provider: $this->provider());
        } finally {
            $this->budget->refund($reserved);
        }

        $assistant = $conversation->messages()->create([
            'role' => Role::Assistant,
            'content' => $this->normalise($response->text),
            'usage' => $response->usage->toArray(),
        ]);

        $conversation->touchActivity();

        return $assistant;
    }

    /**
     * Answers the conversation's trailing visitor turn, which the caller is
     * expected to have persisted already so it renders before the stream opens.
     *
     * @param  callable(string): void  $onDelta
     */
    public function streamReply(Conversation $conversation, callable $onDelta): Message
    {
        $prompt = $conversation->pendingPrompt();

        $reserved = $this->reserve($prompt);

        $full = '';
        $toolCalls = [];
        $toolResults = [];
        $usage = null;

        try {
            foreach ($this->agent($conversation)->stream($prompt, provider: $this->provider()) as $event) {
                if ($event instanceof TextDelta) {
                    $delta = $this->normalise($event->delta);

                    if ($delta !== '') {
                        $full .= $delta;
                        $onDelta($delta);
                    }
                } elseif ($event instanceof ToolCall) {
                    $toolCalls[] = $event->toArray();
                } elseif ($event instanceof ToolResult) {
                    $toolResults[] = $event->toArray();
                } elseif ($event instanceof StreamEnd) {
                    $usage = $event->usage->toArray();
                }
            }
        } finally {
            $this->budget->refund($reserved);
        }

        $assistant = $conversation->messages()->create([
            'role' => Role::Assistant,
            'content' => $full,
            'tool_calls' => $toolCalls === [] ? null : $toolCalls,
            'tool_results' => $toolResults === [] ? null : $toolResults,
            'usage' => $usage,
        ]);

        $conversation->touchActivity();

        return $assistant;
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

    private function agent(Conversation $conversation): AriaAgent
    {
        return new AriaAgent($conversation, $this->persona, $this->tools);
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

    private function record(Conversation $conversation, Role $role, string $content): Message
    {
        return $conversation->messages()->create(['role' => $role, 'content' => $content]);
    }

    /**
     * Applied to everything a model returns. A product that wants smart quotes
     * turned into straight ones sets its own normaliser here.
     */
    private function normalise(string $text): string
    {
        $normaliser = Config::get('aria.normaliser');

        return is_callable($normaliser) ? (string) $normaliser($text) : $text;
    }
}
