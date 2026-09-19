<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use NoriaLabs\Aria\Aria;

/**
 * The read surface over a thread. Writes during a turn belong to the SDK's
 * ConversationStore, which also rebuilds tool calls and approval pauses into
 * replayable messages - none of which an Eloquent relation would get right.
 *
 * @property string $id
 * @property string|null $scope
 * @property string $corpus
 * @property string|null $visitor_key
 * @property string $title
 */
class Conversation extends AriaModel
{
    protected string $ariaTable = 'conversations';

    protected $casts = [
        'meta' => 'array',
    ];

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Aria::messageModel(), 'conversation_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** @return MorphTo<covariant \Illuminate\Database\Eloquent\Model, $this> */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }
}
