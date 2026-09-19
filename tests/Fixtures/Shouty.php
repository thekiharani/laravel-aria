<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Tests\Fixtures;

use NoriaLabs\Aria\Contracts\Normaliser;

class Shouty implements Normaliser
{
    public function normalise(string $text): string
    {
        return mb_strtoupper($text);
    }
}
