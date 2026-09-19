<?php

declare(strict_types=1);

return [
    'provider' => env('ARIA_PROVIDER', 'openai'),
    'model' => env('ARIA_MODEL', ''),

    'table_prefix' => env('ARIA_TABLE_PREFIX', 'aria_'),

    'embeddings' => [
        'provider' => env('ARIA_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('ARIA_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('ARIA_EMBEDDINGS_DIMENSIONS', 1536),
        'cache' => (bool) env('ARIA_EMBEDDINGS_CACHE', true),
    ],

    'retrieval' => [
        'limit' => (int) env('ARIA_RETRIEVAL_LIMIT', 6),
        'min_similarity' => (float) env('ARIA_RETRIEVAL_MIN_SIMILARITY', 0.3),
        'chunk_characters' => (int) env('ARIA_CHUNK_CHARACTERS', 1500),
    ],

    'tools' => [
        'knowledge_search' => [
            'description' => 'Search the knowledge base for facts before answering anything factual. Returns the most relevant entries with their URLs.',
            'empty' => 'No matching entries were found. Say you are not certain rather than guessing.',
        ],
    ],

    /*
     * Redacted before a prompt leaves. What counts as sensitive differs by
     * product and by country, so it is config rather than code.
     */
    'masking' => [
        'patterns' => [
            'email' => '/[\w.+-]+@[\w-]+\.[\w.-]+/',
            'card' => '/\b(?:\d[ -]*?){13,19}\b/',
        ],
    ],

    /*
     * USD micros per million tokens. A model absent from this list is charged
     * at the dearest rate here and logged, never at zero - a cap counting
     * zeroes never fires.
     */
    'pricing' => [
        'gpt-4o-mini' => ['input' => 150_000, 'output' => 600_000],
        'gpt-4o' => ['input' => 2_500_000, 'output' => 10_000_000],
        'text-embedding-3-small' => ['input' => 20_000, 'output' => 0],
    ],

    /*
     * Applied to every model response. Set to a callable to normalise output -
     * the Noria site passes its ASCII normaliser here.
     */
    'normaliser' => null,
];
