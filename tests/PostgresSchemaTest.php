<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * The package is Postgres-first: jsonb rather than json, and a real vector
 * column. SQLite cannot show either, so these run only where there is a pg to
 * run them against.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Postgres-only schema assertions.');
    }
});

function columnType(string $table, string $column): string
{
    $row = DB::selectOne(
        'select data_type, udt_name from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    );

    return $row === null ? '' : ($row->udt_name ?? $row->data_type);
}

it('stores documents as jsonb, not json, so they can be indexed', function (): void {
    foreach ([['aria_conversations', 'meta'], ['aria_messages', 'tool_calls'], ['aria_messages', 'usage']] as [$table, $column]) {
        expect(columnType($table, $column))->toBe('jsonb', "{$table}.{$column}");
    }
});

it('gives chunks a real vector column', function (): void {
    expect(columnType('aria_chunks', 'embedding'))->toBe('vector');
});

it('keys and references every relation on uuid', function (): void {
    expect(columnType('aria_messages', 'conversation_id'))->toBe('uuid');
    expect(columnType('aria_chunks', 'document_id'))->toBe('uuid');
    expect(columnType('aria_conversations', 'id'))->toBe('uuid');
});

it('constrains the foreign keys against the prefixed tables', function (): void {
    $count = DB::scalar(
        "select count(*) from information_schema.table_constraints
         where constraint_type = 'FOREIGN KEY' and table_name in ('aria_messages', 'aria_chunks')",
    );

    expect((int) $count)->toBe(2);
});

it('uses text for the unbounded columns and bounded varchars everywhere else', function (): void {
    expect(columnType('aria_messages', 'content'))->toBe('text');
    expect(columnType('aria_runs', 'error'))->toBe('text');

    $lengths = DB::select(
        "select table_name, column_name, character_maximum_length len
         from information_schema.columns
         where table_name like 'aria_%' and character_maximum_length is not null",
    );

    foreach ($lengths as $column) {
        $n = (int) $column->len;

        // Powers of two, or Laravel's unbounded string default of 255.
        $power = ($n & ($n - 1)) === 0;

        expect($power || $n === 255)->toBeTrue(
            "{$column->table_name}.{$column->column_name} is varchar({$n})",
        );
    }
});
