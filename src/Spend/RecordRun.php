<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Spend;

use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Prompts\AgentPrompt;
use NoriaLabs\Aria\Aria;
use NoriaLabs\Aria\Contracts\BudgetPolicy;

/**
 * Every call the SDK makes, written down with what it cost.
 *
 * The agent, provider and model are on the prompt rather than the event. Read
 * from the event they come back null, the model prices as '', RunCost charges
 * the ceiling rate for every call ever made, and the log fills with warnings
 * about a model nobody named.
 */
class RecordRun
{
    private const ERROR_LIMIT = 1_000;

    public function __construct(
        private BudgetPolicy $policy,
        private Budget $budget,
    ) {}

    /** Also the listener for AgentStreamed, which extends AgentPrompted. */
    public function prompted(AgentPrompted $event): void
    {
        $usage = $event->response->usage;
        $model = $event->prompt->model;
        $cost = RunCost::of($model, $usage);

        $this->write([
            'agent' => $event->prompt->agent::class,
            'provider' => $this->providerOf($event->prompt),
            'model' => $model,
            'status' => 'succeeded',
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cost_usd_micros' => $cost,
        ]);

        $this->budget->record($cost);
    }

    public function failed(AgentFailed $event): void
    {
        $this->write([
            'agent' => $event->prompt->agent::class,
            'provider' => $this->providerOf($event->prompt),
            'model' => $event->prompt->model,
            'status' => 'failed',
            'error' => mb_substr($event->exception->getMessage(), 0, self::ERROR_LIMIT),
        ]);
    }

    /**
     * Embeddings report a plain token count rather than a Usage, and only ever
     * consume input, so the cost is built from the count rather than a Usage.
     */
    public function embedded(EmbeddingsGenerated $event): void
    {
        $cost = RunCost::ofTokens($event->model, input: $event->response->tokens);

        $this->write([
            'agent' => 'embeddings',
            'provider' => $event->provider->name(),
            'model' => $event->model,
            'status' => 'succeeded',
            'prompt_tokens' => $event->response->tokens,
            'cost_usd_micros' => $cost,
        ]);

        $this->budget->record($cost);
    }

    /** @param array<string, mixed> $attributes */
    private function write(array $attributes): void
    {
        Aria::runModel()::query()->create($attributes + ['scope' => $this->policy->scope()]);
    }

    /**
     * The connection name the app configured, not the driver, so two OpenAI
     * connections pointed at different keys stay apart in the ledger.
     */
    private function providerOf(AgentPrompt $prompt): string
    {
        return $prompt->provider->name();
    }
}
