<?php

declare(strict_types=1);

namespace NoriaLabs\Aria;

use InvalidArgumentException;
use NoriaLabs\Aria\Models\Chunk;
use NoriaLabs\Aria\Models\Conversation;
use NoriaLabs\Aria\Models\Document;
use NoriaLabs\Aria\Models\Message;
use NoriaLabs\Aria\Models\Run;
use NoriaLabs\Aria\Models\SpendLedger;

/**
 * The package's configuration surface: which models it reads through, and
 * what its tables are called.
 *
 * Models are swappable because a host that cannot add a relation, a scope or
 * a trait to a package's model ends up forking the package. Call the setters
 * from a service provider's register().
 */
final class Aria
{
    /** @var class-string<Conversation> */
    private static string $conversationModel = Conversation::class;

    /** @var class-string<Message> */
    private static string $messageModel = Message::class;

    /** @var class-string<Document> */
    private static string $documentModel = Document::class;

    /** @var class-string<Chunk> */
    private static string $chunkModel = Chunk::class;

    /** @var class-string<Run> */
    private static string $runModel = Run::class;

    /** @var class-string<SpendLedger> */
    private static string $spendLedgerModel = SpendLedger::class;

    public static function useConversationModel(string $model): void
    {
        self::$conversationModel = self::subclass($model, Conversation::class);
    }

    public static function useMessageModel(string $model): void
    {
        self::$messageModel = self::subclass($model, Message::class);
    }

    public static function useDocumentModel(string $model): void
    {
        self::$documentModel = self::subclass($model, Document::class);
    }

    public static function useChunkModel(string $model): void
    {
        self::$chunkModel = self::subclass($model, Chunk::class);
    }

    public static function useRunModel(string $model): void
    {
        self::$runModel = self::subclass($model, Run::class);
    }

    public static function useSpendLedgerModel(string $model): void
    {
        self::$spendLedgerModel = self::subclass($model, SpendLedger::class);
    }

    /** @return class-string<Conversation> */
    public static function conversationModel(): string
    {
        return self::$conversationModel;
    }

    /** @return class-string<Message> */
    public static function messageModel(): string
    {
        return self::$messageModel;
    }

    /** @return class-string<Document> */
    public static function documentModel(): string
    {
        return self::$documentModel;
    }

    /** @return class-string<Chunk> */
    public static function chunkModel(): string
    {
        return self::$chunkModel;
    }

    /** @return class-string<Run> */
    public static function runModel(): string
    {
        return self::$runModel;
    }

    /** @return class-string<SpendLedger> */
    public static function spendLedgerModel(): string
    {
        return self::$spendLedgerModel;
    }

    /**
     * The name of one of Aria's tables: the explicit override if the host set
     * one, otherwise the prefix. Read by both the models and the migration,
     * so the two can never disagree about where a table lives.
     */
    public static function table(string $name): string
    {
        $configured = config('aria.tables.'.$name);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return ((string) config('aria.table_prefix', 'aria_')).$name;
    }

    public static function connection(): ?string
    {
        $connection = config('aria.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /** Returns every model to its default. For tests, and for nothing else. */
    public static function forgetModels(): void
    {
        self::$conversationModel = Conversation::class;
        self::$messageModel = Message::class;
        self::$documentModel = Document::class;
        self::$chunkModel = Chunk::class;
        self::$runModel = Run::class;
        self::$spendLedgerModel = SpendLedger::class;
    }

    /**
     * @template TModel of object
     *
     * @param  class-string<TModel>  $contract
     * @return class-string<TModel>
     */
    private static function subclass(string $model, string $contract): string
    {
        if (! is_a($model, $contract, allow_string: true)) {
            throw new InvalidArgumentException($model.' must extend '.$contract.'.');
        }

        return $model;
    }
}
