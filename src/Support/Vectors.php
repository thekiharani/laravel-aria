<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether this database can do vector search.
 *
 * Postgres is not enough on its own: pgvector is installed per database, not
 * per cluster, and a managed host may not let the application enable it. So
 * the answer is "the extension is present", not "the driver is pgsql", and it
 * is asked again at read time because the column may never have been created.
 */
final class Vectors
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return self::$available = false;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (Throwable) {
            // No permission to install it; fall through and ask whether
            // somebody else already did.
        }

        try {
            return self::$available = DB::scalar(
                "select count(*) from pg_extension where extname = 'vector'",
            ) > 0;
        } catch (Throwable) {
            return self::$available = false;
        }
    }

    /** The column only exists where the extension did at migration time. */
    public static function indexed(): bool
    {
        $table = config('aria.table_prefix', 'aria_').'chunks';

        return self::available() && Schema::hasColumn($table, 'embedding');
    }

    public static function flush(): void
    {
        self::$available = null;
    }
}
