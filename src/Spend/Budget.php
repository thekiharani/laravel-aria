<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Spend;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use NoriaLabs\Aria\Aria;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Models\SpendLedger;

/**
 * What a scope has spent this period, and whether it may spend more. One row
 * rather than a sum over runs, because a cap checked by aggregating a log gets
 * slower every month.
 *
 * The check is a reservation, not a read: read-then-call is check-then-act, and
 * a hundred requests together all see the same sub-cap total and all proceed.
 */
class Budget
{
    /**
     * What a call is assumed to cost before it is made. Four characters to a
     * token is the usual rough conversion, and the rate is the dearest on the
     * list rather than the likeliest: a reservation that undercounts is a cap
     * that can be walked through by arriving in numbers.
     */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(private BudgetPolicy $policy) {}

    public function allows(): bool
    {
        return $this->hasHeadroom(0);
    }

    /**
     * Claims room for a call before making it, all or nothing. True means the
     * estimate is already counted and the caller owes a refund() once the run
     * has settled, whether it succeeded or not.
     */
    public function reserve(int $estimateUsdMicros): bool
    {
        $estimate = max($estimateUsdMicros, 0);

        $ledger = $this->ledger();
        $cap = $this->policy->cap();

        if ($cap === null) {
            return true;
        }

        // The condition travels with the update, so the row is read and written
        // under one lock and two callers cannot both find the same last dollar.
        return $this->ledgerQuery()
            ->whereKey($ledger->getKey())
            ->whereRaw('spend_usd_micros + ? <= ?', [$estimate, $cap])
            ->update(['spend_usd_micros' => DB::raw('spend_usd_micros + '.$estimate)]) === 1;
    }

    /** Gives back what reserve() took, floored so a double refund cannot mint budget. */
    public function refund(int $estimateUsdMicros): void
    {
        $estimate = max($estimateUsdMicros, 0);

        if ($estimate === 0 || $this->policy->cap() === null) {
            return;
        }

        $this->ledgerQuery()
            ->whereKey($this->ledger()->getKey())
            // CASE rather than GREATEST: the latter is Postgres and MySQL only,
            // and a package does not choose the host application's database.
            ->update(['spend_usd_micros' => DB::raw(
                'CASE WHEN spend_usd_micros > '.$estimate.' THEN spend_usd_micros - '.$estimate.' ELSE 0 END'
            )]);
    }

    /** What it actually cost, once the run has settled. */
    public function record(int $usdMicros): void
    {
        $spend = max($usdMicros, 0);

        if ($spend === 0) {
            return;
        }

        $this->ledgerQuery()
            ->whereKey($this->ledger()->getKey())
            ->update(['spend_usd_micros' => DB::raw('spend_usd_micros + '.$spend)]);
    }

    public function estimateFor(string $input): int
    {
        $tokens = (int) ceil(mb_strlen($input) / self::CHARS_PER_TOKEN);
        $table = config('aria.pricing');

        $dearest = 0;

        foreach (is_array($table) ? $table : [] as $prices) {
            $dearest = max($dearest, (int) (is_array($prices) ? ($prices['output'] ?? 0) : 0));
        }

        // Output is assumed to match input in length, which is generous for a
        // chat turn and the point: the reservation should never undercount.
        return intdiv($tokens * 2 * $dearest, 1_000_000);
    }

    public function spentThisPeriod(): int
    {
        return (int) $this->ledger()->getAttribute('spend_usd_micros');
    }

    private function hasHeadroom(int $estimateUsdMicros): bool
    {
        $cap = $this->policy->cap();

        if ($cap === null) {
            return true;
        }

        return $this->spentThisPeriod() + max($estimateUsdMicros, 0) <= $cap;
    }

    /** @return Builder<SpendLedger> */
    private function ledgerQuery(): Builder
    {
        /** @var Builder<SpendLedger> */
        return Aria::spendLedgerModel()::query();
    }

    private function ledger(): SpendLedger
    {
        return $this->ledgerQuery()->firstOrCreate(
            ['scope' => $this->policy->scope(), 'period' => now()->format('Y-m')],
            ['spend_usd_micros' => 0],
        );
    }
}
