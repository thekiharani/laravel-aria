<?php

declare(strict_types=1);

use Laravel\Ai\Responses\Data\Usage;
use NoriaLabs\Aria\Spend\RunCost;

function usage(int $prompt = 0, int $completion = 0): Usage
{
    return new Usage(promptTokens: $prompt, completionTokens: $completion);
}

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
