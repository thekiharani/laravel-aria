<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Aria\Aria;
use NoriaLabs\Aria\Support\Vectors;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Aria::connection();
    }

    public function up(): void
    {
        $conversations = Aria::table('conversations');
        $documents = Aria::table('documents');

        /*
         * The conversation and message columns are the SDK's, because the
         * SDK's ConversationStore writes and reads them. Aria adds corpus,
         * scope and visitor_key, and replaces the SDK's bigint participant_id
         * with a string so a host whose users have UUID keys can still name
         * one. Renaming a column here breaks the store, not just a query.
         */
        Schema::create($conversations, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable()->index();
            $table->string('corpus', 64)->nullable()->index();
            $table->string('participant_type', 256)->nullable();
            $table->string('participant_id', 64)->nullable();
            $table->string('visitor_key', 128)->nullable()->index();
            $table->string('title', 256);
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'aria_conversations_participant_idx');
        });

        Schema::create(Aria::table('messages'), function (Blueprint $table) use ($conversations): void {
            $table->uuid('id')->primary();
            // Named explicitly rather than let foreignIdFor derive it: the
            // SDK's store queries conversation_id, so a host swapping the
            // model must not rename the column out from under it.
            $table->foreignIdFor(Aria::conversationModel(), 'conversation_id')
                ->constrained(table: $conversations)
                ->cascadeOnDelete();
            $table->string('participant_type', 256)->nullable();
            $table->string('participant_id', 64)->nullable();
            $table->string('agent', 256);
            $table->string('role', 32);
            $table->text('content');
            $table->jsonb('attachments');
            $table->jsonb('tool_calls');
            $table->jsonb('tool_results');
            $table->jsonb('usage');
            $table->jsonb('meta');
            $table->jsonb('approval_state')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id'], 'aria_messages_window_idx');
        });

        Schema::create($documents, function (Blueprint $table): void {
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

        Schema::create(Aria::table('chunks'), function (Blueprint $table) use ($documents): void {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Aria::documentModel(), 'document_id')
                ->constrained(table: $documents)
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

            Schema::table(Aria::table('chunks'), function (Blueprint $table) use ($dimensions): void {
                $table->vector('embedding', $dimensions)->nullable();
            });
        }

        Schema::create(Aria::table('runs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable()->index();
            $table->string('agent', 256)->nullable();
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

        Schema::create(Aria::table('spend_ledger'), function (Blueprint $table): void {
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
        foreach (['spend_ledger', 'runs', 'chunks', 'documents', 'messages', 'conversations'] as $table) {
            Schema::dropIfExists(Aria::table($table));
        }
    }
};
