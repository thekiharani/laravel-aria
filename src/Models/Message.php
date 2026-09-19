<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NoriaLabs\Aria\Enums\Role;

/**
 * @property string $id
 * @property Role $role
 * @property string $content
 */
class Message extends AriaModel
{
    protected string $ariaTable = 'messages';

    protected $casts = [
        'role' => Role::class,
        'tool_calls' => 'array',
        'tool_results' => 'array',
        'usage' => 'array',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
