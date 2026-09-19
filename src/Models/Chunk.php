<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Models;

use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property string $content
 */
class Chunk extends AriaModel
{
    protected string $ariaTable = 'chunks';

    /**
     * The vector column exists on pgsql only - see the migration - so the cast
     * that reads it has to be conditional too, or every other driver throws on
     * a column it never created.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $casts = ['embedded_at' => 'datetime'];

        if (static::vectorsSupported()) {
            $casts['embedding'] = AsVector::class;
        }

        return $casts;
    }

    public static function vectorsSupported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
