<?php

declare(strict_types=1);

use Laravel\Ai\Ai;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use NoriaLabs\Aria\Agents\AriaAgent;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Models\Run;
use NoriaLabs\Aria\Models\SpendLedger;
use NoriaLabs\Aria\Spend\Budget;
use NoriaLabs\Aria\Spend\RecordRun;
use NoriaLabs\Aria\Spend\RunCost;
use NoriaLabs\Aria\Tests\Fixtures\CappedPolicy;
use NoriaLabs\Aria\Tests\Fixtures\StubPersona;

function usage(int $prompt = 0, int $completion = 0): Usage
{
    return new Usage(promptTokens: $prompt, completionTokens: $completion);
}

function agentPrompt(string $model = 'cheap-model'): AgentPrompt
{
    return new AgentPrompt(
        agent: new AriaAgent(new StubPersona),
        prompt: 'hello',
        attachments: [],
        provider: Ai::textProvider('openai'),
        model: $model,
    );
}

function answered(string $model = 'cheap-model', int $prompt = 1_000_000, int $completion = 0): AgentPrompted
{
    return new AgentPrompted(
        invocationId: 'inv',
        prompt: agentPrompt($model),
        response: new AgentResponse('inv', 'hi', usage($prompt, $completion), new Meta),
    );
}

describe('pricing a run', function (): void {
    it('prices a run from the tokens the provider reported', function (): void {
        // 1M in at 100_000 micros, 1M out at 200_000 micros.
        expect(RunCost::of('cheap-model', usage(prompt: 1_000_000, completion: 1_000_000)))
            ->toBe(300_000);
    });

    it('charges a model nobody priced at the dearest rate on the list', function (): void {
        $unlisted = RunCost::of('a-model-nobody-priced', usage(prompt: 1_000_000));
        $dearest = RunCost::of('dear-model', usage(prompt: 1_000_000));

        expect($unlisted)->toBe($dearest);
    });

    it('never charges an unpriced model nothing, because a cap counting zeroes never fires', function (): void {
        expect(RunCost::of('a-model-nobody-priced', usage(prompt: 500_000, completion: 500_000)))
            ->toBeGreaterThan(0);
    });

    it('costs an empty run at nothing', function (): void {
        expect(RunCost::of('cheap-model', usage()))->toBe(0);
    });
});

describe('holding a budget', function (): void {
    beforeEach(function (): void {
        CappedPolicy::$cap = null;
        CappedPolicy::$scope = null;
        app()->bind(BudgetPolicy::class, CappedPolicy::class);
    });

    it('lets an uncapped scope spend without reserving anything', function (): void {
        $budget = app(Budget::class);

        expect($budget->allows())->toBeTrue();
        expect($budget->reserve(5_000_000))->toBeTrue();
    });

    it('reserves against the cap before the call is made', function (): void {
        CappedPolicy::$cap = 1_000;
        $budget = app(Budget::class);

        expect($budget->reserve(600))->toBeTrue();
        expect($budget->spentThisPeriod())->toBe(600);
    });

    it('refuses a reservation that would cross the cap', function (): void {
        CappedPolicy::$cap = 1_000;
        $budget = app(Budget::class);

        expect($budget->reserve(900))->toBeTrue();
        expect($budget->reserve(200))->toBeFalse();
        expect($budget->spentThisPeriod())->toBe(900);
    });

    it('does not let two callers both take the last of the budget', function (): void {
        CappedPolicy::$cap = 1_000;
        $budget = app(Budget::class);

        $granted = collect(range(1, 5))->filter(fn (): bool => $budget->reserve(300))->count();

        expect($granted)->toBe(3);
        expect($budget->spentThisPeriod())->toBeLessThanOrEqual(1_000);
    });

    it('gives a reservation back when the run does not happen', function (): void {
        CappedPolicy::$cap = 1_000;
        $budget = app(Budget::class);

        $budget->reserve(600);
        $budget->refund(600);

        expect($budget->spentThisPeriod())->toBe(0);
    });

    it('cannot mint budget by refunding twice', function (): void {
        CappedPolicy::$cap = 1_000;
        $budget = app(Budget::class);

        $budget->reserve(400);
        $budget->refund(400);
        $budget->refund(400);

        expect($budget->spentThisPeriod())->toBe(0);
    });

    it('keeps each scope on its own ledger', function (): void {
        CappedPolicy::$cap = 1_000;

        CappedPolicy::$scope = 'workspace-a';
        app(Budget::class)->reserve(500);

        CappedPolicy::$scope = 'workspace-b';
        app()->forgetInstance(Budget::class);

        expect(app(Budget::class)->spentThisPeriod())->toBe(0);
        expect(SpendLedger::query()->count())->toBe(2);
    });

    it('estimates generously, so a burst cannot walk through the cap', function (): void {
        $budget = app(Budget::class);

        expect($budget->estimateFor(str_repeat('a', 4_000)))->toBeGreaterThan(0);
        expect($budget->estimateFor('short'))
            ->toBeLessThan($budget->estimateFor(str_repeat('a', 4_000)));
    });
});

describe('writing a run down', function (): void {
    it('names the agent, provider and model the call actually used', function (): void {
        app(RecordRun::class)->prompted(answered());

        $run = Run::query()->sole();

        expect($run->agent)->toBe(AriaAgent::class);
        expect($run->provider)->toBe('openai');
        expect($run->model)->toBe('cheap-model');
        expect($run->status)->toBe('succeeded');
    });

    /*
     * The regression the whole block exists for. Agent, provider and model
     * live on the prompt, not on the event. Read from the event they come back
     * null, the model prices as '', and every call ever made is charged at the
     * ceiling rate.
     */
    it('prices the run at the rate of its own model, not the ceiling rate', function (): void {
        app(RecordRun::class)->prompted(answered('cheap-model', prompt: 1_000_000));

        expect((int) Run::query()->sole()->cost_usd_micros)->toBe(100_000);
    });

    it('keeps the token counts the provider reported', function (): void {
        app(RecordRun::class)->prompted(answered(prompt: 40, completion: 25));

        $run = Run::query()->sole();

        expect((int) $run->prompt_tokens)->toBe(40);
        expect((int) $run->completion_tokens)->toBe(25);
    });

    it('counts what the run cost against the budget for the period', function (): void {
        app(RecordRun::class)->prompted(answered());

        expect(app(Budget::class)->spentThisPeriod())->toBe(100_000);
    });

    /*
     * The dispatcher walks a class's interfaces, never its parents, so a
     * listener registered on AgentPrompted alone leaves every streamed turn
     * unrecorded and unbilled - which is the path the chat widget takes.
     */
    it('bills a streamed turn as well as a plain one', function (): void {
        event(new AgentStreamed(
            invocationId: 'inv',
            prompt: agentPrompt(),
            response: new AgentResponse('inv', 'hi', usage(prompt: 1_000_000), new Meta),
        ));

        expect(Run::query()->count())->toBe(1);
        expect(app(Budget::class)->spentThisPeriod())->toBe(100_000);
    });

    it('records a failure with its reason and charges nothing for it', function (): void {
        app(RecordRun::class)->failed(new AgentFailed(
            invocationId: 'inv',
            prompt: agentPrompt(),
            exception: new RuntimeException('the provider timed out'),
        ));

        $run = Run::query()->sole();

        expect($run->status)->toBe('failed');
        expect($run->error)->toBe('the provider timed out');
        expect((int) $run->cost_usd_micros)->toBe(0);
        expect(app(Budget::class)->spentThisPeriod())->toBe(0);
    });

    it('truncates a runaway error rather than refusing to record the run', function (): void {
        app(RecordRun::class)->failed(new AgentFailed(
            invocationId: 'inv',
            prompt: agentPrompt(),
            exception: new RuntimeException(str_repeat('x', 5_000)),
        ));

        expect(mb_strlen((string) Run::query()->sole()->error))->toBe(1_000);
    });

    it('bills embedding work too, so indexing is not free in the ledger', function (): void {
        app(RecordRun::class)->embedded(new EmbeddingsGenerated(
            invocationId: 'inv',
            provider: Ai::embeddingProvider('openai'),
            model: 'cheap-model',
            prompt: new EmbeddingsPrompt(['hello'], 1_536, Ai::embeddingProvider('openai'), 'cheap-model'),
            response: new EmbeddingsResponse([[0.1]], 1_000_000, new Meta),
        ));

        $run = Run::query()->sole();

        expect($run->agent)->toBe('embeddings');
        expect((int) $run->cost_usd_micros)->toBe(100_000);
        expect(app(Budget::class)->spentThisPeriod())->toBe(100_000);
    });

    it('tags every run with the scope that paid for it', function (): void {
        CappedPolicy::$cap = null;
        CappedPolicy::$scope = 'workspace-a';
        app()->bind(BudgetPolicy::class, CappedPolicy::class);

        app(RecordRun::class)->prompted(answered());

        expect(Run::query()->sole()->scope)->toBe('workspace-a');
    });
});
