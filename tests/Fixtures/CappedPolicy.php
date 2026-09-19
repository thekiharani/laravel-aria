<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Tests\Fixtures;

use NoriaLabs\Aria\Contracts\BudgetPolicy;

class CappedPolicy implements BudgetPolicy
{
    public static ?int $cap = null;

    public static ?string $scope = null;

    public function cap(): ?int
    {
        return self::$cap;
    }

    public function scope(): ?string
    {
        return self::$scope;
    }
}
