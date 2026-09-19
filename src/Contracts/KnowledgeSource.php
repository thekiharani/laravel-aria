<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Contracts;

use NoriaLabs\Aria\Knowledge\KnowledgeDocument;

interface KnowledgeSource
{
    /**
     * Everything the assistant may retrieve, for this corpus.
     *
     * @return iterable<int, KnowledgeDocument>
     */
    public function documents(): iterable;

    /**
     * Names the corpus so several products can index into one database without
     * reading each other's documents.
     */
    public function corpus(): string;
}
