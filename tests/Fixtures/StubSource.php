<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Tests\Fixtures;

use NoriaLabs\Aria\Contracts\KnowledgeSource;
use NoriaLabs\Aria\Knowledge\KnowledgeDocument;

class StubSource implements KnowledgeSource
{
    /** @var list<KnowledgeDocument> */
    public static array $documents = [];

    public function documents(): iterable
    {
        return self::$documents;
    }

    public function corpus(): string
    {
        return 'stub';
    }
}
