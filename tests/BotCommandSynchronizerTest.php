<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CodexMultitenant\TransportTelegram\BotCommandSynchronizer;
use CodexMultitenant\TransportTelegram\BotManagementClientFactoryInterface;
use CodexMultitenant\TransportTelegram\BotManagementClientInterface;
use CodexMultitenant\TransportTelegram\BotRecord;
use CodexMultitenant\TransportTelegram\BotRegistryInterface;
use CodexMultitenant\TransportTelegram\RuntimeLog;

final class CommandSyncRegistry implements BotRegistryInterface
{
    /** @param list<BotRecord> $bots */
    public function __construct(private array $bots) {}
    public function enabledBots(): array { return $this->bots; }
    public function botByKey(string $bot_key): BotRecord { throw new RuntimeException('unused'); }
    public function botByTelegramUserId(int $telegram_user_id): ?BotRecord { return null; }
    public function defaultBot(): BotRecord { throw new RuntimeException('unused'); }
    public function activeBotForChat(string $chat_id): BotRecord { throw new RuntimeException('unused'); }
    public function explicitBotForChat(string $chat_id): ?BotRecord { return null; }
    public function setActiveBotForChat(string $chat_id, string $bot_key, string $chat_type): void {}
    public function register(BotRecord $record): void {}
    public function hasTelegramUserId(int $telegram_user_id): bool { return false; }
    public function protectedBots(): array { return []; }
    public function activeGroupChatIds(string $bot_key): array { return []; }
    public function deleteRoutesForBot(string $bot_key): void {}
    public function deleteBot(string $bot_key): void {}
}

final class CommandSyncClient implements BotManagementClientInterface
{
    public array $command_sets = [];
    public function __construct(private bool $fail = false) {}
    public function getMe(): array { return []; }
    public function setMyCommands(array $commands, ?array $scope = null): void
    {
        if ($this->fail) {
            throw new RuntimeException('revoked token');
        }
        $this->command_sets[] = [$commands, $scope];
    }
    public function setWebhook(string $url, string $secret_token, string $ip_address): void {}
    public function deleteWebhook(): void {}
    public function deleteMessage(int|string $chat_id, int $message_id): void {}
    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array { return []; }
    public function leaveChat(int|string $chat_id): void {}
}

final class CommandSyncClientFactory implements BotManagementClientFactoryInterface
{
    /** @var array<string, CommandSyncClient> */
    public array $clients = [];
    public array $events = [];
    public array $failing_bot_keys = [];
    public function __construct(?array &$events = null)
    {
        if ($events !== null) {
            $this->events =& $events;
        }
    }
    public function create(BotRecord $bot): BotManagementClientInterface
    {
        $this->events[] = 'sync:' . $bot->bot_key;
        return $this->clients[$bot->bot_key] ??= new CommandSyncClient(in_array($bot->bot_key, $this->failing_bot_keys, true));
    }
    public function createFromToken(string $token): BotManagementClientInterface { throw new RuntimeException('unused'); }
}

function testSynchronizerIsolatesRevokedBotToken(): void
{
    $factory = new CommandSyncClientFactory();
    $factory->failing_bot_keys = ['b'];
    $logs = [];
    RuntimeLog::setObserver(static function (array $payload) use (&$logs): void {
        $logs[] = $payload;
    });

    try {
        (new BotCommandSynchronizer(
            new CommandSyncRegistry([syncBot('a', 1), syncBot('b', 2), syncBot('c', 3)]),
            $factory
        ))->syncAll();
    } finally {
        RuntimeLog::setObserver(null);
    }

    assertSame(3, count($factory->clients['a']->command_sets), 'first bot synchronized');
    assertSame(0, count($factory->clients['b']->command_sets), 'revoked bot rejected');
    assertSame(3, count($factory->clients['c']->command_sets), 'bot after revoked token synchronized');
    $errors = array_values(array_filter($logs, static fn (array $log): bool => ($log['level'] ?? '') === 'ERROR'));
    assertSame('b', $errors[0]['context']['bot_key'] ?? null, 'failed bot key logged');
    assertSame('b', $errors[0]['context']['username'] ?? null, 'failed bot username logged');
    assertSame('revoked token', $errors[0]['context']['error'] ?? null, 'sync error logged');
}

function syncBot(string $key, int $id): BotRecord
{
    return new BotRecord($key, $id, $key, $key . '-token', $key . '-secret', $key === 'a');
}

function testSynchronizerUpdatesCommandsForEveryEnabledBot(): void
{
    $factory = new CommandSyncClientFactory();
    $synchronizer = new BotCommandSynchronizer(
        new CommandSyncRegistry([syncBot('a', 1), syncBot('b', 2)]),
        $factory
    );

    $synchronizer->syncAll();

    assertSame(['a', 'b'], array_keys($factory->clients), 'all enabled bot clients created');
    foreach ($factory->clients as $bot_key => $client) {
        assertSame(
            ['default', 'all_private_chats', 'all_chat_administrators'],
            array_map(static fn (array $set): string => (string) ($set[1]['type'] ?? ''), $client->command_sets),
            "all command scopes updated for {$bot_key}"
        );
        assertTrue(
            in_array('model', array_column($client->command_sets[1][0], 'command'), true),
            "model command installed for {$bot_key}"
        );
    }
}

testSynchronizerUpdatesCommandsForEveryEnabledBot();
testSynchronizerIsolatesRevokedBotToken();

fwrite(STDOUT, "Bot command synchronizer tests: OK\n");
