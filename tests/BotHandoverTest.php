<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\BotClientFactoryInterface;
use CodexMultitenant\TransportTelegram\BotHandoverHandler;
use CodexMultitenant\TransportTelegram\BotRecord;
use CodexMultitenant\TransportTelegram\BotRegistryInterface;
use CodexMultitenant\TransportTelegram\BotTokenCipher;
use CodexMultitenant\TransportTelegram\ChatLockInterface;
use CodexMultitenant\TransportTelegram\HandoverBotClientInterface;

require_once __DIR__ . '/bootstrap.php';

final class MemoryBotRegistry implements BotRegistryInterface
{
    /** @param array<string, BotRecord> $bots */
    public function __construct(public array $bots, public ?string $default_key, public array $routes = []) {}
    public function botByKey(string $bot_key): BotRecord { return $this->bots[$bot_key]; }
    public function defaultBot(): BotRecord { return $this->bots[(string) $this->default_key]; }
    public function activeBotForChat(string $chat_id): BotRecord { return $this->bots[$this->routes[$chat_id] ?? (string) $this->default_key]; }
    public function explicitBotForChat(string $chat_id): ?BotRecord { return isset($this->routes[$chat_id]) ? $this->bots[$this->routes[$chat_id]] : null; }
    public function setActiveBotForChat(string $chat_id, string $bot_key): void { $this->routes[$chat_id] = $bot_key; }
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
    $handler = new BotHandoverHandler($registry, $lock, $factory);

    assertTrue($handler->handle($new, joinedUpdate('-100')), 'handover update handled');
    assertSame(['telegram-bot-handover:-100'], $lock->keys, 'one chat lock');
    assertSame('new', $registry->routes['-100'], 'new bot active');
    assertSame([['-100', 'О, коллега! Привет. Пост сдал.']], $factory->clients['old']->messages, 'old bot farewell');
    assertSame([['-100', 'Пост принял.']], $factory->clients['new']->messages, 'new bot greeting');
    assertSame(['-100'], $factory->clients['old']->left_chats, 'old bot left');
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

testBotTokenCipherRoundTrip();
testHandoverReplacesActiveBotUnderOneChatLock();
testNextHandoverSeesPreviousNewBotAsOld();
