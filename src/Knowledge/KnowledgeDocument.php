<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Knowledge;

use Illuminate\Support\Carbon;

final readonly class KnowledgeDocument
{
    public function __construct(
        public string $sourceType,
        public string $sourceKey,
        public string $title,
        public string $body,
        public ?string $sourceUrl = null,
        public ?string $description = null,
        public ?Carbon $sourceUpdatedAt = null,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->title."\n".$this->body."\n".($this->sourceUrl ?? ''));
    }
}
