<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use NoriaLabs\Aria\Models\Chunk;
use NoriaLabs\Aria\Models\Conversation;

it('creates every table the package needs, prefixed', function (): void {
    foreach (['conversations', 'messages', 'documents', 'chunks', 'runs', 'spend_ledger'] as $name) {
        expect(Schema::hasTable('aria_'.$name))->toBeTrue("missing aria_{$name}");
    }
});

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

it('renames the prefix across every table at once', function (): void {
    config(['aria.table_prefix' => 'noria_ai_']);

    expect((new Conversation)->getTable())->toBe('noria_ai_conversations');
    expect((new Chunk)->getTable())->toBe('noria_ai_chunks');
});
