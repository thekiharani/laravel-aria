<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use NoriaLabs\Aria\Enums\Role;

/**
 * @property string $id
 * @property string|null $scope
 * @property string $corpus
 * @property string|null $visitor_key
 */
class Conversation extends AriaModel
{
    protected string $ariaTable = 'conversations';

    protected $casts = [
        'meta' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at')->orderBy('id');
    }

    public function lastMessage(): ?Message
    {
        return $this->messages()->reorder()->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /** The trailing visitor turn, which is what a stream is answering. */
    public function pendingPrompt(): string
    {
        $last = $this->lastMessage();

        return $last?->role === Role::User ? $last->content : '';
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }
}
