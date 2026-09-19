<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class AriaModel extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('aria.table_prefix', 'aria_').$this->ariaTable;
    }

    /** The unprefixed table name, so a host app can prefix every table at once. */
    protected string $ariaTable = '';
}
