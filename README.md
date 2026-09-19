# laravel-aria

[![CI](https://github.com/thekiharani/laravel-aria/actions/workflows/ci.yml/badge.svg)](https://github.com/thekiharani/laravel-aria/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/thekiharani/laravel-aria)](https://packagist.org/packages/thekiharani/laravel-aria)

The layer between `laravel/ai` and a product: retrieval over a corpus you supply, spend
governance, PII masking, and somewhere for a conversation to live.

`laravel/ai` gives you agents, tools, providers, streaming, events, conversation persistence and
approval resume. This adds the part every product was otherwise writing again: an index that only
re-embeds what changed, a cap that holds under concurrency, redaction before text is stored or
sent, and a persona seam so the same engine is a sales assistant on one site and a pipeline
assistant in another.

```
laravel/ai          agents - tools - providers - streaming - conversations - events
    ^
laravel-aria        retrieval - spend - masking - persona
    ^
your application    a KnowledgeSource, a Persona, your own tools
```

Nothing the SDK already does is reimplemented here. Conversations are stored by the SDK's
`ConversationStore`, pointed at this package's tables; history, tool-call replay and approval
resume come from `RemembersConversations`; the agent's ceilings resolve through the SDK's own
method-over-attribute seam.

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
$response = app(Assistant::class)->reply('Do you do mobile money reconciliation?');

$response->text;            // the answer
$response->conversationId;  // hold this to continue the thread
```

Continue it, or attach it to a signed-in user:

```php
app(Assistant::class)->reply($message, $conversationId);
app(Assistant::class)->reply($message, participant: $user);
```

`stream()` returns the SDK's own `StreamableAgentResponse`, so a controller can return it straight
for SSE, or you can pass a callback:

```php
return app(Assistant::class)->stream($message, $conversationId);

app(Assistant::class)->stream($message, $conversationId, onDelta: fn (string $d) => $this->stream('answer', $d));
```

An anonymous visitor's first turn creates its own thread. The SDK only remembers a turn that
already has a conversation or a participant, and a website visitor has neither.

Build the index with `php artisan aria:index`, or `--fresh` to re-embed everything rather than
only what moved.

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

`AgentStreamed` is listened for in its own right. It extends `AgentPrompted`, but the dispatcher
walks a class's interfaces and never its parents, so a listener on the parent alone leaves every
streamed turn unbilled.

## Customising

| Knob | How |
|---|---|
| Provider, model | `aria.provider`, `aria.model` |
| Turn ceilings | `aria.limits.max_steps`, `max_tokens`, `timeout` |
| Replayed history | `aria.history` |
| Embeddings | `aria.embeddings.*` |
| Retrieval | `aria.retrieval.*` |
| Redaction | `aria.masking.enabled`, `aria.masking.patterns` |
| Prices | `aria.pricing` |
| Table names | `aria.table_prefix`, or `aria.tables.<name>` for one |
| Database | `aria.connection` |
| Models | `Aria::useConversationModel(...)` and friends |
| Output | `aria.normaliser` |

Swap a model from a service provider's `register()`:

```php
Aria::useConversationModel(SupportThread::class);
```

A host that cannot add a relation, a scope or a trait to a package's model forks the package.
Substitutes must extend the model they replace, and they keep its table.

The normaliser is **a class name, not a callable**. `config:cache` var_exports the config array and
throws on a closure, so a callable here passes every test and breaks the first deploy.

```php
class Ascii implements NoriaLabs\Aria\Contracts\Normaliser
{
    public function normalise(string $text): string { /* ... */ }
}
```

It is applied to what the caller receives. The copy the store keeps is the provider's own text.

Masking runs at the `Assistant` boundary, not as SDK prompt middleware. Middleware rewrites the
prompt on its way to the provider, but the store writes the prompt it was handed, so the raw
address would be kept and replayed to the provider as history on the next turn.

## Databases

Postgres-first: `jsonb` rather than `json`, UUID v7 keys throughout, `foreignIdFor()` constraints,
`text` only where the length is genuinely unbounded, and bounded `varchar` everywhere else.

The conversation and message columns are the SDK's, because the SDK's store reads and writes them.
Aria adds `corpus`, `scope` and `visitor_key`, and makes `participant_id` a string rather than the
SDK's bigint, so a host whose users have UUID keys can still name one. Renaming one of those
columns breaks the store, not merely a query.

Vector search needs Postgres **and** pgvector. The migration tries `CREATE EXTENSION IF NOT EXISTS
vector` and, if the extension is not there afterwards, adds no column at all - a managed host that
will not let you install it still migrates, and retrieval falls back to `LIKE` rather than
pretending the results are as good. One migration, six tables.

Several products can share one database. Documents, chunks and conversations are keyed by corpus,
and a search only ever reads its own.

### Publishing and overriding

```bash
php artisan vendor:publish --tag=aria-migrations
```

Then set `ARIA_LOAD_MIGRATIONS=false`, or every table is created twice - once from the package path
and once from `database/migrations`.

## Testing against it

```php
use Laravel\Ai\Embeddings;
use NoriaLabs\Aria\Agents\AriaAgent;

AriaAgent::fake(['Yes, we do.']);
Embeddings::fake();
```

Then `reply()`, `aria:index` and `search()` all work with no provider and no key.

## A cost the SDK will charge you for

`ai.conversations.generate_title` is on by default, and it spends a second, cheaper model call to
name each new thread. Aria titles an anonymous thread from its opening message instead, so that
call only happens on the participant path - but it is a provider call this package's budget never
reserved against. Set it to false if the cap has to be exact.
