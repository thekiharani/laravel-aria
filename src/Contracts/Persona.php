<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Contracts;

interface Persona
{
    /**
     * Who the assistant is here, and what it must never say. The whole system
     * prompt: the package supplies none of it, because a shared persona is one
     * that fits nowhere.
     */
    public function instructions(): string;

    /**
     * Sent as the opening assistant turn when a conversation has none, or null
     * to let the visitor speak first.
     */
    public function greeting(): ?string;
}
