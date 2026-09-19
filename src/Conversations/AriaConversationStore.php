<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Conversations;

use Laravel\Ai\Storage\DatabaseConversationStore;
use NoriaLabs\Aria\Aria;
use NoriaLabs\Aria\Contracts\BudgetPolicy;
use NoriaLabs\Aria\Contracts\KnowledgeSource;

/**
 * The SDK's own store, pointed at Aria's tables and stamped with a corpus.
 *
 * Subclassed rather than reimplemented. The parent rebuilds a stored turn's
 * tool calls and results into replayable messages and resolves approval
 * pauses - several hundred lines that any hand-rolled history would have to
 * get right, and that an earlier version of this package simply did not have:
 * it replayed user and assistant text only, so the model never saw what a
 * tool had already returned and searched for the same fact every turn.
 */
class AriaConversationStore extends DatabaseConversationStore
{
    public function __construct(
        private KnowledgeSource $source,
        private BudgetPolicy $policy,
    ) {
        parent::__construct(Aria::connection());
    }

    /**
     * Conversations carry the corpus they were had against, so two products
     * sharing a database never read each other's threads, and the scope that
     * pays for them.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        $conversationId = parent::storeConversation($participantType, $participantId, $title);

        $this->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->update([
                'corpus' => $this->source->corpus(),
                'scope' => $this->policy->scope(),
            ]);

        return $conversationId;
    }

    protected function conversationsTable(): string
    {
        return Aria::table('conversations');
    }

    protected function messagesTable(): string
    {
        return Aria::table('messages');
    }
}
