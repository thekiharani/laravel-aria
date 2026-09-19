<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Contracts;

interface BudgetPolicy
{
    /**
     * What this scope may spend in the current period, in USD micros, or null
     * for uncapped. A tenanted app reads its workspace settings here; an app
     * with one tenant returns a constant or null.
     */
    public function cap(): ?int;

    /**
     * Who the spend and the conversations belong to, or null where the app has
     * no tenancy. Written to every row the package creates, so a tenanted app's
     * own global scopes still apply on top.
     */
    public function scope(): ?string;
}
