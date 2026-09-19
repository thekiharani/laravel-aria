<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use NoriaLabs\Aria\Aria;

/**
 * @property string $id
 * @property string $corpus
 * @property string $title
 * @property string|null $source_url
 * @property string $source_hash
 */
class Document extends AriaModel
{
    protected string $ariaTable = 'documents';

    protected $casts = ['source_updated_at' => 'datetime'];

    /** @return HasMany<Chunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(Aria::chunkModel(), 'document_id');
    }
}
