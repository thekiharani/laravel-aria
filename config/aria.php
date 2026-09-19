<?php

declare(strict_types=1);

return [
    'provider' => env('ARIA_PROVIDER', 'openai'),
    'model' => env('ARIA_MODEL', ''),

    /*
     * Null keeps Aria on the default connection. Point it at another to put
     * the assistant's tables on a database of their own.
     */
    'connection' => env('ARIA_DB_CONNECTION'),

    'table_prefix' => env('ARIA_TABLE_PREFIX', 'aria_'),

    /*
     * Per-table overrides. A null entry falls back to the prefix, so renaming
     * one table does not mean spelling out the other five.
     */
    'tables' => [
        'conversations' => env('ARIA_TABLE_CONVERSATIONS'),
        'messages' => env('ARIA_TABLE_MESSAGES'),
        'documents' => env('ARIA_TABLE_DOCUMENTS'),
        'chunks' => env('ARIA_TABLE_CHUNKS'),
        'runs' => env('ARIA_TABLE_RUNS'),
        'spend_ledger' => env('ARIA_TABLE_SPEND_LEDGER'),
    ],

    /*
     * Set false after publishing the migrations, or every table is created
     * twice - once from the package path and once from database/migrations.
     */
    'record_runs' => (bool) env('ARIA_RECORD_RUNS', true),

    'load_migrations' => (bool) env('ARIA_LOAD_MIGRATIONS', true),

    /*
     * How many stored turns are replayed as context. The SDK reads this
     * through the agent, and each one is tokens on every subsequent turn.
     */
    'history' => (int) env('ARIA_HISTORY', 20),

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

    /*
     * Ceilings for a single turn, read by the agent. They are config rather
     * than PHP attributes because a support assistant and a research one want
     * different answers and should not need different agent classes.
     */
    'limits' => [
        'max_steps' => (int) env('ARIA_MAX_STEPS', 6),
        'max_tokens' => (int) env('ARIA_MAX_TOKENS', 1500),
        'timeout' => (int) env('ARIA_TIMEOUT', 60),
    ],

    'tools' => [
        'knowledge_search' => [
            'description' => 'Search the knowledge base for facts before answering anything factual. Returns the most relevant entries with their URLs.',
            'empty' => 'No matching entries were found. Say you are not certain rather than guessing.',
        ],
    ],

    /*
     * Redacted before a prompt is persisted or sent. What counts as sensitive
     * differs by product and by country, so it is config rather than code.
     */
    'masking' => [
        'enabled' => (bool) env('ARIA_MASKING', true),
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
     * A class implementing NoriaLabs\Aria\Contracts\Normaliser, applied to
     * every model response. It is a class name and not a closure on purpose:
     * config:cache var_exports the config array and throws on a closure, so a
     * callable here would pass every test and break the first deploy.
     */
    'normaliser' => null,
];
