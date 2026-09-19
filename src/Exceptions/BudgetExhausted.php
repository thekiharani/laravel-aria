<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Exceptions;

use RuntimeException;

class BudgetExhausted extends RuntimeException
{
    public static function forPeriod(): self
    {
        return new self('The AI budget for this period is exhausted.');
    }
}
