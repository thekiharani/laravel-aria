<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('aria.table_prefix', 'aria_');

        Schema::create($prefix.'conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable()->index();
            $table->string('corpus', 64)->index();
            $table->string('visitor_key', 128)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create($prefix.'messages', function (Blueprint $table) use ($prefix): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained($prefix.'conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->json('tool_calls')->nullable();
            $table->json('tool_results')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create($prefix.'documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->nullable()->index();
            $table->string('corpus', 64);
            $table->string('source_type', 64);
            $table->string('source_key', 191);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_url')->nullable();
            $table->string('source_hash', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['corpus', 'source_type', 'source_key']);
        });

        Schema::create($prefix.'chunks', function (Blueprint $table) use ($prefix): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained($prefix.'documents')->cascadeOnDelete();
            $table->string('chunk_name', 64)->default('body');
            $table->unsignedInteger('chunk_index')->default(0);
            $table->longText('content');
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

        // The vector column is added separately: only pgsql gets a real vector,
        // and everywhere else falls back to a LIKE search with no column at all.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
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
            $table->string('period', 7);
            $table->unsignedBigInteger('spend_usd_micros')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'period']);
        });
    }

    public function down(): void
    {
        $prefix = config('aria.table_prefix', 'aria_');

        foreach (['spend_ledger', 'runs', 'chunks', 'documents', 'messages', 'conversations'] as $table) {
            Schema::dropIfExists($prefix.$table);
        }
    }
};
