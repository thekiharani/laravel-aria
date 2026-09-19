<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Support;

use NoriaLabs\Aria\Contracts\BudgetPolicy;

/**
 * The default for an app with one tenant and no cap. Spend is still recorded -
 * an app that cannot see what it spends cannot decide to cap it later.
 */
final class Unmetered implements BudgetPolicy
{
    public function cap(): ?int
    {
        return null;
    }

    public function scope(): ?string
    {
        return null;
    }
}
