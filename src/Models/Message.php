<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NoriaLabs\Aria\Aria;

/**
 * @property string $id
 * @property string $role
 * @property string $content
 * @property string $agent
 */
class Message extends AriaModel
{
    protected string $ariaTable = 'messages';

    protected $casts = [
        'attachments' => 'array',
        'tool_calls' => 'array',
        'tool_results' => 'array',
        'usage' => 'array',
        'meta' => 'array',
        'approval_state' => 'array',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Aria::conversationModel(), 'conversation_id');
    }
}
