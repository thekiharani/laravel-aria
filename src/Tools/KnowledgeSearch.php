<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use NoriaLabs\Aria\Knowledge\KnowledgeIndex;

class KnowledgeSearch implements Tool
{
    public function __construct(private KnowledgeIndex $index) {}

    public function description(): string
    {
        return (string) config(
            'aria.tools.knowledge_search.description',
            'Search the knowledge base for facts before answering anything factual. Returns the most relevant entries with their URLs.',
        );
    }

    public function handle(Request $request): string
    {
        $value = $request['query'] ?? null;
        $query = trim(is_string($value) ? $value : '');

        if ($query === '') {
            return 'No query provided.';
        }

        $results = $this->index->search($query);

        if ($results === []) {
            return (string) config(
                'aria.tools.knowledge_search.empty',
                'No matching entries were found. Say you are not certain rather than guessing.',
            );
        }

        return collect($results)
            ->map(function (array $result): string {
                $heading = $result['title'];

                if (is_string($result['url']) && $result['url'] !== '') {
                    $heading .= ' ['.$result['url'].']';
                }

                return '## '.$heading."\n".$result['content'];
            })
            ->implode("\n\n");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The question or search keywords.')
                ->required(),
        ];
    }
}
