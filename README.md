# laravel-aria

The layer between `laravel/ai` and a product: conversations, retrieval over a corpus you supply,
spend governance, and PII masking.

`laravel/ai` gives you agents, tools, providers, streaming and embeddings. This gives you the part
every product was otherwise writing again: somewhere to keep a conversation, an index that only
re-embeds what changed, a cap that holds under concurrency, and a persona seam so the same engine
can be a sales assistant on one site and a pipeline assistant in another.

```
laravel/ai          agents · tools · providers · streaming · events
    ↑
laravel-aria        conversations · retrieval · spend · masking
    ↑
your application    a KnowledgeSource, a Persona, your own tools
```

## Install

```bash
composer require thekiharani/laravel-aria
php artisan vendor:publish --tag=aria-config
php artisan migrate
```

## What you implement

Three things, and nothing else.

```php
use NoriaLabs\Aria\Contracts\KnowledgeSource;
use NoriaLabs\Aria\Knowledge\KnowledgeDocument;

class SiteKnowledge implements KnowledgeSource
{
    public function documents(): iterable
    {
        foreach (config('lanes') as $lane) {
            yield new KnowledgeDocument(
                sourceType: 'lane',
                sourceKey: $lane['slug'],
                title: $lane['title'],
                body: $lane['headline']."\n".$lane['lede'],
                sourceUrl: '/'.$lane['slug'],
            );
        }
    }

    public function corpus(): string
    {
        return 'noria-site';
    }
}
```

```php
use NoriaLabs\Aria\Contracts\Persona;

class Aria implements Persona
{
    public function instructions(): string
    {
        return 'You are Aria. Never quote a price unless asked. ...';
    }

    public function greeting(): ?string
    {
        return null;
    }
}
```

Bind them, and tag any extra tools:

```php
$this->app->bind(KnowledgeSource::class, SiteKnowledge::class);
$this->app->bind(Persona::class, Aria::class);
$this->app->tag([CaptureLead::class], 'aria.tools');
```

Nothing is bound for you. A default persona is one every product has to remember to replace, and
forgetting is silent.

## Use

```php
$conversation = Conversation::create(['corpus' => 'noria-site']);

app(Assistant::class)->reply($conversation, 'Do you do M-PESA reconciliation?');
```

`streamReply` takes a callable rather than owning a transport, which is why the same runner serves
a Livewire island, an SSE controller and a queued job without knowing which it is talking to:

```php
app(Assistant::class)->streamReply($conversation, fn (string $delta) => $this->stream('answer', $delta));
```

Build the index with `php artisan aria:index`, or `--fresh` to re-embed everything rather than only
what moved.

## Tenancy and spend

An app with one tenant gets `Unmetered` and needs to do nothing. Spend is still recorded, because
an app that cannot see what it spends cannot decide to cap it later.

A tenanted app implements `BudgetPolicy`:

```php
class WorkspaceBudget implements BudgetPolicy
{
    public function cap(): ?int      { return $this->settings->aiCapUsdMicros(); }
    public function scope(): ?string { return Tenancy::currentWorkspaceId(); }
}
```

The cap is **a reservation, not a read**. A plain read-then-call is check-then-act, and a hundred
requests arriving together all see the same sub-cap total and all proceed. `reserve()` claims room
before the call and the runner refunds it after, whatever happened.

A model missing from `aria.pricing` is charged at the dearest rate on the list and logged. Costing
it at zero would read as thrift in the ledger while quietly switching every cap off.

## Databases

Postgres-first: `jsonb` rather than `json`, UUID v7 keys throughout, `foreignIdFor()` constraints,
`text` only where the length is genuinely unbounded, and bounded `varchar` everywhere else.

Vector search needs Postgres **and** pgvector. The migration tries `CREATE EXTENSION IF NOT EXISTS
vector` and, if the extension is not there afterwards, adds no column at all - a managed host that
will not let you install it still migrates, and retrieval falls back to `LIKE` rather than
pretending the results are as good. One migration, six tables.

Several products can share one database. Documents and chunks are keyed by corpus, and a search
only ever reads its own.

### Publishing and overriding

```bash
php artisan vendor:publish --tag=aria-migrations
```

Then set `ARIA_LOAD_MIGRATIONS=false`, or every table is created twice - once from the package path
and once from `database/migrations`. Rename the whole set at once with `ARIA_TABLE_PREFIX`.

## Testing against it

```php
use Laravel\Ai\Embeddings;

Embeddings::fake();
```

Then `aria:index` and `search()` work with no provider and no key.
