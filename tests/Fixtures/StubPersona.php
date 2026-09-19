<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Tests\Fixtures;

use NoriaLabs\Aria\Contracts\Persona;

class StubPersona implements Persona
{
    public function instructions(): string
    {
        return 'You are a test assistant.';
    }

    public function greeting(): ?string
    {
        return null;
    }
}
