<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Contracts;

/**
 * Applied to everything a model returns. The Noria site passes its ASCII
 * normaliser here so curly quotes never reach a page that bans them.
 */
interface Normaliser
{
    public function normalise(string $text): string;
}
