<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\BotClientFactoryInterface;
use CodexMultitenant\TransportTelegram\BotHandoverHandler;
use CodexMultitenant\TransportTelegram\BotAwareIncomingUpdateHandler;
use CodexMultitenant\TransportTelegram\BotRecord;
use CodexMultitenant\TransportTelegram\BotRegistryInterface;
use CodexMultitenant\TransportTelegram\BotTokenCipher;
use CodexMultitenant\TransportTelegram\ChatLockInterface;
use CodexMultitenant\TransportTelegram\HandoverBotClientInterface;
use CodexMultitenant\TransportTelegram\IncomingUpdateHandlerInterface;
use CodexMultitenant\TransportTelegram\StatusMessageResetInterface;

require_once __DIR__ . '/bootstrap.php';

final class MemoryBotRegistry implements BotRegistryInterface
{
    /** @param array<string, BotRecord> $bots */
    public function __construct(public array $bots, public ?string $default_key, public array $routes = []) {}
    public function botByKey(string $bot_key): BotRecord { return $this->bots[$bot_key]; }
    public function botByTelegramUserId(int $telegram_user_id): ?BotRecord { foreach ($this->bots as $bot) if ($bot->telegram_user_id === $telegram_user_id) return $bot; return null; }
    public function defaultBot(): BotRecord { return $this->bots[(string) $this->default_key]; }
    public function activeBotForChat(string $chat_id): BotRecord { return $this->bots[$this->routes[$chat_id] ?? (string) $this->default_key]; }
    public function explicitBotForChat(string $chat_id): ?BotRecord { return isset($this->routes[$chat_id]) ? $this->bots[$this->routes[$chat_id]] : null; }
    public function setActiveBotForChat(string $chat_id, string $bot_key, string $chat_type): void { $this->routes[$chat_id] = $bot_key; }
    public function register(BotRecord $record): void { $this->bots[$record->bot_key] = $record; }
    public function hasTelegramUserId(int $telegram_user_id): bool { foreach ($this->bots as $bot) if ($bot->telegram_user_id === $telegram_user_id) return true; return false; }
    public function protectedBots(): array { return array_values(array_filter($this->bots, fn(BotRecord $bot) => $bot->deletion_protected)); }
    public function activeGroupChatIds(string $bot_key): array { return []; }
    public function deleteRoutesForBot(string $bot_key): void { foreach ($this->routes as $chat => $key) if ($key === $bot_key) unset($this->routes[$chat]); }
    public function deleteBot(string $bot_key): void { unset($this->bots[$bot_key]); }
}

final class ImmediateChatLock implements ChatLockInterface
{
    public array $keys = [];
    public function synchronized(string $key, callable $callback): mixed
    {
        $this->keys[] = $key;
        return $callback();
    }
}

final class HandoverClient implements HandoverBotClientInterface
{
    public array $messages = [];
    public array $left_chats = [];
    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array
    {
        $this->messages[] = [(string) $chat_id, $text];
        return ['ok' => true];
    }
    public function leaveChat(int|string $chat_id): void { $this->left_chats[] = (string) $chat_id; }
}

final class MemoryBotClientFactory implements BotClientFactoryInterface
{
    /** @var array<string, HandoverClient> */
    public array $clients = [];
    public function create(BotRecord $bot): HandoverBotClientInterface
    {
        return $this->clients[$bot->bot_key] ??= new HandoverClient();
    }
}

final class RecordingIncomingHandler implements IncomingUpdateHandlerInterface
{
    public array $updates = [];
    public function handleIncomingUpdate(array $update): array
    {
        $this->updates[] = $update;
        return ['accepted' => true, 'messages' => []];
    }
}

final class RecordingStatusReset implements StatusMessageResetInterface
{
    public array $chat_ids = [];
    public function resetForChat(string $chat_id): void { $this->chat_ids[] = $chat_id; }
}

function bot(string $key, int $telegram_id): BotRecord
{
    return new BotRecord($key, $telegram_id, $key, $key . '-token', $key . '-secret', false);
}

function joinedUpdate(string $chat_id): array
{
    return ['my_chat_member' => ['chat' => ['id' => $chat_id, 'type' => 'supergroup'], 'new_chat_member' => ['status' => 'administrator']]];
}

function testBotTokenCipherRoundTrip(): void
{
    $cipher = new BotTokenCipher(base64_encode(random_bytes(32)));
    $encrypted = $cipher->encrypt('123:secret-token');
    assertTrue($encrypted !== '123:secret-token', 'token encrypted');
    assertSame('123:secret-token', $cipher->decrypt($encrypted), 'token decrypted');
}

function testHandoverReplacesActiveBotUnderOneChatLock(): void
{
    $old = bot('old', 1);
    $new = bot('new', 2);
    $registry = new MemoryBotRegistry(['old' => $old, 'new' => $new], 'old', ['-100' => 'old']);
    $lock = new ImmediateChatLock();
    $factory = new MemoryBotClientFactory();
    $status_reset = new RecordingStatusReset();
    $handler = new BotHandoverHandler($registry, $lock, $factory, $status_reset);

    assertTrue($handler->handle($new, joinedUpdate('-100')), 'handover update handled');
    assertSame(['telegram-bot-handover:-100'], $lock->keys, 'one chat lock');
    assertSame('new', $registry->routes['-100'], 'new bot active');
    assertSame([['-100', 'О, коллега! Привет. Пост сдал.']], $factory->clients['old']->messages, 'old bot farewell');
    assertSame([['-100', 'Пост принял.']], $factory->clients['new']->messages, 'new bot greeting');
    assertSame(['-100'], $factory->clients['old']->left_chats, 'old bot left');
    assertSame(['-100'], $status_reset->chat_ids, 'group status reset');
}

function testNextHandoverSeesPreviousNewBotAsOld(): void
{
    $a = bot('a', 1); $b = bot('b', 2); $c = bot('c', 3);
    $registry = new MemoryBotRegistry(['a' => $a, 'b' => $b, 'c' => $c], 'a', ['-100' => 'a']);
    $factory = new MemoryBotClientFactory();
    $handler = new BotHandoverHandler($registry, new ImmediateChatLock(), $factory);

    $handler->handle($b, joinedUpdate('-100'));
    $handler->handle($c, joinedUpdate('-100'));

    assertSame('c', $registry->routes['-100'], 'latest bot active');
    assertSame(['-100'], $factory->clients['b']->left_chats, 'middle bot became old and left');
}

function testHandoverTreatsDefaultBotAsOldWithoutExplicitRoute(): void
{
    $old = bot('old', 1); $new = bot('new', 2);
    $registry = new MemoryBotRegistry(['old' => $old, 'new' => $new], 'old');
    $factory = new MemoryBotClientFactory();
    $handler = new BotHandoverHandler($registry, new ImmediateChatLock(), $factory);

    $handler->handle($new, joinedUpdate('-200'));

    assertSame('new', $registry->routes['-200'], 'new bot replaces implicit default');
    assertSame(['-200'], $factory->clients['old']->left_chats, 'implicit default bot left');
}

function testPrivateMessageActivatesReceivingBotBeforeForwarding(): void
{
    $a = bot('a', 1); $b = bot('b', 2);
    $registry = new MemoryBotRegistry(['a' => $a, 'b' => $b], 'a');
    $lock = new ImmediateChatLock();
    $next = new RecordingIncomingHandler();
    $status_reset = new RecordingStatusReset();
    $handler = new BotAwareIncomingUpdateHandler(
        $b,
        $registry,
        $lock,
        new BotHandoverHandler($registry, $lock, new MemoryBotClientFactory(), $status_reset),
        $next,
        null,
        $status_reset
    );
    $update = ['message' => ['chat' => ['id' => 55, 'type' => 'private'], 'text' => 'hello']];

    $handler->handleIncomingUpdate($update);

    assertSame('b', $registry->routes['55'], 'receiving private bot active');
    assertSame(['telegram-bot-route:55'], $lock->keys, 'private route lock');
    assertSame([$update], $next->updates, 'private update forwarded');
    assertSame(['55'], $status_reset->chat_ids, 'private status reset');
}

function testInactiveBotGroupMessageIsNotForwarded(): void
{
    $old = bot('old', 1); $new = bot('new', 2);
    $registry = new MemoryBotRegistry(['old' => $old, 'new' => $new], 'old', ['-100' => 'new']);
    $next = new RecordingIncomingHandler();
    $lock = new ImmediateChatLock();
    $handler = new BotAwareIncomingUpdateHandler(
        $old,
        $registry,
        $lock,
        new BotHandoverHandler($registry, $lock, new MemoryBotClientFactory()),
        $next
    );

    $result = $handler->handleIncomingUpdate(['message' => ['chat' => ['id' => -100, 'type' => 'supergroup'], 'text' => 'hello']]);

    assertSame(false, $result['accepted'], 'inactive group bot rejected');
    assertSame([], $next->updates, 'inactive group update not forwarded');
}

testBotTokenCipherRoundTrip();
testHandoverReplacesActiveBotUnderOneChatLock();
testNextHandoverSeesPreviousNewBotAsOld();
testHandoverTreatsDefaultBotAsOldWithoutExplicitRoute();
testPrivateMessageActivatesReceivingBotBeforeForwarding();
testInactiveBotGroupMessageIsNotForwarded();
