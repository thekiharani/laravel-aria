<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Support;

use NoriaLabs\Aria\Contracts\Normaliser;

/** The default: the model's text, untouched. */
final class PlainText implements Normaliser
{
    public function normalise(string $text): string
    {
        return $text;
    }
}
