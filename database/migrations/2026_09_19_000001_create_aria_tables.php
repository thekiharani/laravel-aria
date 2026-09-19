<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Aria\Models\Conversation;
use NoriaLabs\Aria\Models\Document;
use NoriaLabs\Aria\Support\Vectors;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = (string) config('aria.table_prefix', 'aria_');

        Schema::create($prefix.'conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable()->index();
            $table->string('corpus', 64)->index();
            $table->string('visitor_key', 128)->nullable()->index();
            $table->jsonb('meta')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create($prefix.'messages', function (Blueprint $table) use ($prefix): void {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Conversation::class)
                ->constrained(table: $prefix.'conversations')
                ->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->jsonb('tool_calls')->nullable();
            $table->jsonb('tool_results')->nullable();
            $table->jsonb('usage')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create($prefix.'documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable()->index();
            $table->string('corpus', 64);
            $table->string('source_type', 64);
            $table->string('source_key', 256);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_url')->nullable();
            $table->string('source_hash', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['corpus', 'source_type', 'source_key'], 'aria_documents_source_unique');
        });

        Schema::create($prefix.'chunks', function (Blueprint $table) use ($prefix): void {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Document::class)
                ->constrained(table: $prefix.'documents')
                ->cascadeOnDelete();
            $table->string('chunk_name', 64)->default('body');
            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('content');
            $table->string('content_hash', 64);
            $table->unsignedInteger('character_count')->default(0);
            $table->string('embedding_provider', 64);
            $table->string('embedding_model', 128);
            $table->unsignedInteger('embedding_dimensions');
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'chunk_index']);
            $table->index(['embedding_provider', 'embedding_model', 'embedding_dimensions'], 'aria_chunks_embedding_idx');
        });

        // Postgres with pgvector only. Everywhere else - including a Postgres
        // whose operator cannot install the extension - gets no column, no
        // cast, and the index's LIKE fallback, rather than a migration that
        // fails on a type the database has never heard of.
        if (Vectors::available()) {
            $dimensions = (int) config('aria.embeddings.dimensions', 1536);

            Schema::table($prefix.'chunks', function (Blueprint $table) use ($dimensions): void {
                $table->vector('embedding', $dimensions)->nullable();
            });
        }

        Schema::create($prefix.'runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable()->index();
            $table->string('agent', 128)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('status', 16)->default('succeeded');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('cost_usd_micros')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['scope', 'created_at']);
        });

        Schema::create($prefix.'spend_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable();
            $table->string('period', 8);
            $table->unsignedBigInteger('spend_usd_micros')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'period'], 'aria_spend_ledger_period_unique');
        });
    }

    public function down(): void
    {
        $prefix = (string) config('aria.table_prefix', 'aria_');

        foreach (['spend_ledger', 'runs', 'chunks', 'documents', 'messages', 'conversations'] as $table) {
            Schema::dropIfExists($prefix.$table);
        }
    }
};
