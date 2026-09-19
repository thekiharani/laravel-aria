<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Tests\Fixtures;

use NoriaLabs\Aria\Models\Conversation;

/** What a host would write to hang its own relations off a conversation. */
class HostConversation extends Conversation
{
    public function label(): string
    {
        return 'host';
    }
}
