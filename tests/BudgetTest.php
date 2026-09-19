<?php

declare(strict_types=1);

use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Models\SpendLedger;
use NoriaLabs\Aria\Spend\Budget;
use NoriaLabs\Aria\Tests\Fixtures\CappedPolicy;

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
