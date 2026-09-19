<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use NoriaLabs\Aria\Models\Conversation;

it('keys every table on a uuid rather than an auto-increment', function (): void {
    foreach (['conversations', 'messages', 'documents', 'chunks', 'runs', 'spend_ledger'] as $name) {
        $type = Schema::getColumnType('aria_'.$name, 'id');

        expect($type)->not->toBe('integer', "aria_{$name}.id is an integer");
    }
});

it('names foreign keys after the model they point at', function (): void {
    expect(Schema::hasColumn('aria_messages', 'conversation_id'))->toBeTrue();
    expect(Schema::hasColumn('aria_chunks', 'document_id'))->toBeTrue();
});

it('generates version 7 identifiers, so rows sort by when they were written', function (): void {
    $id = (new Conversation)->newUniqueId();

    // The version nibble is the first character of the third group.
    expect(explode('-', $id)[2][0])->toBe('7');
});

it('carries the columns the SDK store reads and writes', function (): void {
    // Renaming one of these breaks the store, not merely a query of our own.
    foreach (['conversation_id', 'participant_type', 'participant_id', 'agent', 'role',
        'content', 'attachments', 'tool_calls', 'tool_results', 'usage', 'meta', 'approval_state'] as $column) {
        expect(Schema::hasColumn('aria_messages', $column))->toBeTrue("aria_messages.{$column}");
    }
});

it('keys a participant by string, so a host with uuid users can name one', function (): void {
    expect(Schema::getColumnType('aria_conversations', 'participant_id'))->not->toBe('integer');
});
