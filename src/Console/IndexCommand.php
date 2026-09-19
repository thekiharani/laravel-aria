<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Console;

use Illuminate\Console\Command;
use NoriaLabs\Aria\Knowledge\KnowledgeIndex;

class IndexCommand extends Command
{
    protected $signature = 'aria:index {--fresh : Re-embed every document, not only those whose content moved}';

    protected $description = 'Rebuild the assistant knowledge index from the configured source';

    public function handle(KnowledgeIndex $index): int
    {
        $this->components->info('Rebuilding the Aria index.');

        $result = $index->rebuild(fresh: (bool) $this->option('fresh'));

        $this->components->twoColumnDetail('Documents', (string) $result['documents']);
        $this->components->twoColumnDetail('Chunks embedded', (string) $result['chunks']);

        return self::SUCCESS;
    }
}
