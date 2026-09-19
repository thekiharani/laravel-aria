<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Spend;

use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Models\Run;

/**
 * Every call the SDK makes, written down with what it cost. Listens to the
 * SDK's own events rather than wrapping calls, so a run is recorded whether it
 * went through the Assistant or straight through an agent.
 */
class RecordRun
{
    public function __construct(
        private BudgetPolicy $policy,
        private Budget $budget,
    ) {}

    public function prompted(AgentPrompted $event): void
    {
        $model = $this->modelOf($event);
        $usage = $event->response->usage;
        $cost = RunCost::of($model, $usage);

        $this->write([
            'agent' => $this->agentOf($event),
            'provider' => $this->providerOf($event),
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
            'agent' => $this->agentOf($event),
            'status' => 'failed',
            'error' => mb_substr($event->exception->getMessage(), 0, 1000),
        ]);
    }

    /**
     * Embeddings report a plain token count rather than a Usage, and only ever
     * consume input, so the cost is built by hand rather than through RunCost.
     */
    public function embedded(EmbeddingsGenerated $event): void
    {
        $cost = RunCost::ofTokens($event->model, input: $event->response->tokens);

        $this->write([
            'agent' => 'embeddings',
            'provider' => $this->providerOf($event),
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
        Run::query()->create($attributes + ['scope' => $this->policy->scope()]);
    }

    private function agentOf(object $event): ?string
    {
        $agent = $event->agent ?? null;

        return is_object($agent) ? $agent::class : (is_string($agent) ? $agent : null);
    }

    /** Provider arrives as an enum on some events and a string on others. */
    private function providerOf(object $event): ?string
    {
        $provider = $event->provider ?? null;

        if ($provider instanceof \BackedEnum) {
            return (string) $provider->value;
        }

        return is_string($provider) ? $provider : null;
    }

    private function modelOf(object $event): string
    {
        $model = $event->model ?? null;

        return is_string($model) ? $model : '';
    }
}
