<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Knowledge;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Contracts\KnowledgeSource;
use NoriaLabs\Aria\Models\Chunk;
use NoriaLabs\Aria\Models\Document;

class KnowledgeIndex
{
    public function __construct(
        private KnowledgeSource $source,
        private BudgetPolicy $budget,
    ) {}

    /**
     * Embeddings are regenerated only where a document's content hash moved, so
     * a rebuild after a copy change costs one document rather than the corpus.
     *
     * @return array{documents: int, chunks: int}
     */
    public function rebuild(bool $fresh = false): array
    {
        $documents = 0;
        $chunks = 0;

        foreach ($this->source->documents() as $document) {
            $hash = $document->hash();

            $existing = Document::query()
                ->where('corpus', $this->source->corpus())
                ->where('source_type', $document->sourceType)
                ->where('source_key', $document->sourceKey)
                ->first();

            $changed = $fresh || $existing === null || $existing->source_hash !== $hash;

            $model = Document::query()->updateOrCreate(
                [
                    'corpus' => $this->source->corpus(),
                    'source_type' => $document->sourceType,
                    'source_key' => $document->sourceKey,
                ],
                [
                    'scope' => $this->budget->scope(),
                    'title' => $document->title,
                    'description' => $document->description,
                    'source_url' => $document->sourceUrl,
                    'source_hash' => $hash,
                    'source_updated_at' => $document->sourceUpdatedAt,
                ],
            );

            $documents++;

            if ($changed) {
                $chunks += $this->syncChunks($model, $document->body);
            }
        }

        return ['documents' => $documents, 'chunks' => $chunks];
    }

    /**
     * @return list<array{title: string, url: string|null, content: string}>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $limit ??= Config::integer('aria.retrieval.limit', 6);

        // Over-fetched, because several chunks of one document are one answer
        // and de-duplicating after the limit would return fewer than asked.
        $fetch = $limit * 3;

        $rows = $this->usesVectors()
            ? $this->scoped()
                ->whereVectorSimilarTo(
                    'embedding',
                    $this->embed($query),
                    minSimilarity: Config::float('aria.retrieval.min_similarity', 0.3),
                )
                ->limit($fetch)
                ->get()
            : $this->scoped()
                ->where(fn ($q) => $q
                    ->where('content', 'like', '%'.$query.'%')
                    ->orWhereHas('document', fn ($d) => $d->where('title', 'like', '%'.$query.'%')))
                ->limit($fetch)
                ->get();

        $results = [];
        $seen = [];

        foreach ($rows as $row) {
            $document = $row->document;

            if ($document === null) {
                continue;
            }

            $key = mb_strtolower(trim($document->title));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $results[] = [
                'title' => $document->title,
                'url' => $document->source_url,
                'content' => $row->content,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /** Chunks belonging to this corpus only, so two products never read each other. */
    /** @return Builder<Chunk> */
    private function scoped(): Builder
    {
        return Chunk::query()
            ->with('document')
            ->whereHas('document', fn ($d) => $d->where('corpus', $this->source->corpus()));
    }

    private function syncChunks(Document $document, string $body): int
    {
        $chunks = $this->chunk($body);

        $document->chunks()
            ->where('embedding_provider', $this->provider())
            ->where('embedding_model', $this->model())
            ->where('embedding_dimensions', $this->dimensions())
            ->delete();

        if ($chunks === []) {
            return 0;
        }

        $pending = Embeddings::for($chunks)->dimensions($this->dimensions());

        if ($this->caches()) {
            $pending = $pending->cache();
        }

        $vectors = $pending
            ->generate(provider: $this->provider(), model: $this->model())
            ->embeddings;

        foreach ($chunks as $index => $chunk) {
            $attributes = [
                'chunk_name' => 'body',
                'chunk_index' => $index,
                'content' => $chunk,
                'content_hash' => hash('sha256', $chunk),
                'character_count' => mb_strlen($chunk),
                'embedding_provider' => $this->provider(),
                'embedding_model' => $this->model(),
                'embedding_dimensions' => $this->dimensions(),
                'embedded_at' => now(),
            ];

            if ($this->usesVectors()) {
                $attributes['embedding'] = $vectors[$index] ?? [];
            }

            $document->chunks()->create($attributes);
        }

        return count($chunks);
    }

    /**
     * The query is embedded with the same provider, model and dimensions the
     * index was built with, or the vectors are not comparable.
     *
     * @return list<float>
     */
    private function embed(string $query): array
    {
        $pending = Embeddings::for([$query])->dimensions($this->dimensions());

        if ($this->caches()) {
            $pending = $pending->cache();
        }

        $vector = $pending
            ->generate(provider: $this->provider(), model: $this->model())
            ->first();

        return array_values($vector);
    }

    private function provider(): string
    {
        return Config::string('aria.embeddings.provider', 'openai');
    }

    private function model(): string
    {
        return Config::string('aria.embeddings.model', 'text-embedding-3-small');
    }

    private function dimensions(): int
    {
        return Config::integer('aria.embeddings.dimensions', 1536);
    }

    /** The host owns the cache store, so it decides whether one is used at all. */
    private function caches(): bool
    {
        return Config::boolean('aria.embeddings.cache', true);
    }

    /**
     * @return list<string>
     */
    private function chunk(string $body): array
    {
        $body = trim($body);

        if ($body === '') {
            return [];
        }

        $ceiling = Config::integer('aria.retrieval.chunk_characters', 1500);

        if (mb_strlen($body) <= $ceiling + 300) {
            return [$body];
        }

        $words = preg_split('/\s+/', $body);
        $words = is_array($words) ? $words : [];

        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            if (mb_strlen($current) + mb_strlen($word) + 1 > $ceiling) {
                $chunks[] = trim($current);
                $current = '';
            }

            $current .= $word.' ';
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /** Only pgsql has a vector column; everything else falls back to LIKE. */
    private function usesVectors(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
