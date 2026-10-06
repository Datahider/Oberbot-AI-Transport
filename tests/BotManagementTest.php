<?php

declare(strict_types=1);

use CodexMultitenant\TransportTelegram\BotManagementHandler;
use CodexMultitenant\TransportTelegram\BotManagementClientFactoryInterface;
use CodexMultitenant\TransportTelegram\BotManagementClientInterface;
use CodexMultitenant\TransportTelegram\BotRecord;
use CodexMultitenant\TransportTelegram\BotRegistryInterface;
use CodexMultitenant\TransportTelegram\PendingBotActionStoreInterface;

require_once __DIR__ . '/bootstrap.php';

final class MemoryPendingBotActionStore implements PendingBotActionStoreInterface
{
    public array $actions = [];
    public function begin(string $bot_key, string $chat_id, int $user_id, string $action, int $expires_at): void { $this->actions["$bot_key:$chat_id:$user_id"] = [$action, $expires_at]; }
    public function find(string $bot_key, string $chat_id, int $user_id, int $now): ?string
    {
        $key = "$bot_key:$chat_id:$user_id";
        $value = $this->actions[$key] ?? null;
        return $value !== null && $value[1] >= $now ? $value[0] : null;
    }
    public function tokenMessageWasDeleted(string $bot_key, string $chat_id, int $user_id, int $message_id): bool { return ($this->actions["$bot_key:$chat_id:$user_id"][2] ?? 0) === $message_id; }
    public function markTokenMessageDeleted(string $bot_key, string $chat_id, int $user_id, int $message_id): void { $this->actions["$bot_key:$chat_id:$user_id"][2] = $message_id; }
    public function clear(string $bot_key, string $chat_id, int $user_id): void { unset($this->actions["$bot_key:$chat_id:$user_id"]); }
}

final class ManagementRegistry implements BotRegistryInterface
{
    public array $bots;
    public array $routes;
    public array $route_types;
    public function __construct(array $bots, array $routes = [], array $route_types = []) { $this->bots = $bots; $this->routes = $routes; $this->route_types = $route_types; }
    public function botByKey(string $bot_key): BotRecord { return $this->bots[$bot_key]; }
    public function botByTelegramUserId(int $telegram_user_id): ?BotRecord { foreach ($this->bots as $bot) if ($bot->telegram_user_id === $telegram_user_id) return $bot; return null; }
    public function defaultBot(): BotRecord { return reset($this->bots); }
    public function activeBotForChat(string $chat_id): BotRecord { return $this->bots[$this->routes[$chat_id] ?? array_key_first($this->bots)]; }
    public function explicitBotForChat(string $chat_id): ?BotRecord { return isset($this->routes[$chat_id]) ? $this->bots[$this->routes[$chat_id]] : null; }
    public function setActiveBotForChat(string $chat_id, string $bot_key, string $chat_type): void { $this->routes[$chat_id] = $bot_key; $this->route_types[$chat_id] = $chat_type; }
    public function register(BotRecord $record): void { $this->bots[$record->bot_key] = $record; }
    public function hasTelegramUserId(int $telegram_user_id): bool { foreach ($this->bots as $bot) if ($bot->telegram_user_id === $telegram_user_id) return true; return false; }
    public function protectedBots(): array { return array_values(array_filter($this->bots, fn(BotRecord $bot) => $bot->deletion_protected)); }
    public function activeGroupChatIds(string $bot_key): array { return array_keys(array_filter($this->routes, fn(string $key, string $chat) => $key === $bot_key && in_array($this->route_types[$chat] ?? '', ['group', 'supergroup'], true), ARRAY_FILTER_USE_BOTH)); }
    public function deleteRoutesForBot(string $bot_key): void { foreach ($this->routes as $chat => $key) if ($key === $bot_key) { unset($this->routes[$chat], $this->route_types[$chat]); } }
    public function deleteBot(string $bot_key): void { unset($this->bots[$bot_key]); }
}

final class ManagementClient implements BotManagementClientInterface
{
    public array $messages = []; public array $deleted_messages = []; public array $left = []; public array $command_sets = []; public bool $webhook_deleted = false; public bool $webhook_set = false;
    public function __construct(public array $identity = []) {}
    public function getMe(): array { return $this->identity; }
    public function setMyCommands(array $commands, ?array $scope = null): void { $this->command_sets[] = [$commands, $scope]; }
    public function setWebhook(string $url, string $secret_token, string $ip_address): void { $this->webhook_set = true; }
    public function deleteWebhook(): void { $this->webhook_deleted = true; }
    public function deleteMessage(int|string $chat_id, int $message_id): void { $this->deleted_messages[] = [(string)$chat_id, $message_id]; }
    public function sendMessage(int|string $chat_id, string $text, ?int $reply_to_message_id = null, ?string $parse_mode = null, bool $disable_notification = false, ?int $message_thread_id = null): array { $this->messages[] = [(string)$chat_id, $text]; return ['ok' => true]; }
    public function leaveChat(int|string $chat_id): void { $this->left[] = (string)$chat_id; }
}

final class ManagementFactory implements BotManagementClientFactoryInterface
{
    public array $by_bot = []; public array $by_token = [];
    public function create(BotRecord $bot): BotManagementClientInterface { return $this->by_bot[$bot->bot_key]; }
    public function createFromToken(string $token): BotManagementClientInterface { return $this->by_token[$token]; }
}

function managedBot(string $key, int $id, bool $protected = false): BotRecord { return new BotRecord($key, $id, $key, "$key-token", "$key-secret", false, $protected); }
function privateUpdate(string $text, int $message_id = 10): array { return ['message' => ['message_id' => $message_id, 'from' => ['id' => 7], 'chat' => ['id' => 7, 'type' => 'private'], 'text' => $text]]; }

function testAddBotConsumesTokenAndRegistersWebhook(): void
{
    $current = managedBot('current', 1, true); $registry = new ManagementRegistry(['current' => $current]); $store = new MemoryPendingBotActionStore(); $factory = new ManagementFactory();
    $current_client = new ManagementClient(); $new_client = new ManagementClient(['id' => 2, 'username' => 'new_bot']); $factory->by_bot['current'] = $current_client; $factory->by_token['2:secret'] = $new_client;
    $handler = new BotManagementHandler($current, $registry, $store, $factory, 'https://webhook.example', '1.2.3.4', fn() => 1000);
    assertTrue($handler->handle(privateUpdate('/addbot')), 'add command handled');
    assertTrue($handler->handle(privateUpdate('2:secret', 11)), 'token handled');
    assertSame([['7', 11]], $current_client->deleted_messages, 'token deleted before processing');
    assertTrue($new_client->webhook_set, 'new webhook configured');
    assertTrue($registry->hasTelegramUserId(2), 'new bot registered');
    assertSame(['default', 'all_private_chats', 'all_chat_administrators'], array_map(fn(array $set) => $set[1]['type'] ?? '', $new_client->command_sets), 'command scopes configured');
    assertTrue(in_array('addbot', array_column($new_client->command_sets[1][0], 'command'), true), 'addbot visible in private');
    assertTrue(!in_array('addbot', array_column($new_client->command_sets[2][0], 'command'), true), 'addbot hidden in groups');
    assertTrue(!in_array('done', array_column($new_client->command_sets[1][0], 'command'), true), 'done hidden in private');
    assertTrue(in_array('done', array_column($new_client->command_sets[2][0], 'command'), true), 'done visible to group admins');
}

function testProtectedBotCannotBeDeleted(): void
{
    $current = managedBot('current', 1, true); $registry = new ManagementRegistry(['current' => $current]); $store = new MemoryPendingBotActionStore(); $factory = new ManagementFactory();
    $client = new ManagementClient(['id' => 1, 'username' => 'current']); $factory->by_bot['current'] = $client; $factory->by_token['1:secret'] = $client;
    $handler = new BotManagementHandler($current, $registry, $store, $factory, 'https://webhook.example', '1.2.3.4', fn() => 1000);
    $handler->handle(privateUpdate('/delbot')); $handler->handle(privateUpdate('1:secret', 12));
    assertTrue(isset($registry->bots['current']), 'protected bot retained');
    assertSame(false, $client->webhook_deleted, 'protected webhook retained');
}

function testAddBotUpdatesExistingTokenWithoutChangingIdentityOrProtection(): void
{
    $current = managedBot('current', 1, true); $existing = managedBot('stable-key', 2, true);
    $registry = new ManagementRegistry(['current' => $current, 'stable-key' => $existing], ['-100' => 'stable-key'], ['-100' => 'supergroup']);
    $store = new MemoryPendingBotActionStore(); $factory = new ManagementFactory();
    $current_client = new ManagementClient(); $updated_client = new ManagementClient(['id' => 2, 'username' => 'renamed_bot']);
    $factory->by_bot['current'] = $current_client; $factory->by_token['2:new-secret'] = $updated_client;
    $handler = new BotManagementHandler($current, $registry, $store, $factory, 'https://webhook.example', '1.2.3.4', fn() => 1000);
    $handler->handle(privateUpdate('/addbot')); $handler->handle(privateUpdate('2:new-secret', 14));
    $updated = $registry->bots['stable-key'];
    assertSame('2:new-secret', $updated->token, 'token updated');
    assertSame('renamed_bot', $updated->username, 'username updated');
    assertSame(true, $updated->deletion_protected, 'protection retained');
    assertSame('stable-key', $registry->routes['-100'], 'route retained');
    assertTrue($updated_client->webhook_set, 'existing webhook reconfigured');
}

function testUpdateTokenCommandShowsProtectedRecoveryBots(): void
{
    $current = managedBot('current', 1); $official = managedBot('official', 2, true);
    $registry = new ManagementRegistry(['current' => $current, 'official' => $official]); $store = new MemoryPendingBotActionStore(); $factory = new ManagementFactory();
    $client = new ManagementClient(); $factory->by_bot['current'] = $client;
    $handler = new BotManagementHandler($current, $registry, $store, $factory, 'https://webhook.example', '1.2.3.4', fn() => 1000);
    assertTrue($handler->handle(privateUpdate('/updatetoken')), 'instruction command handled');
    assertTrue(str_contains($client->messages[0][1] ?? '', '/addbot'), 'addbot instruction shown');
    assertTrue(str_contains($client->messages[0][1] ?? '', '@official'), 'protected recovery bot shown');
}

function testDeleteBotFarewellsGroupsAdvertisesProtectedBotsAndKeepsCoreState(): void
{
    $current = managedBot('current', 1); $official = managedBot('official', 2, true);
    $registry = new ManagementRegistry(['current' => $current, 'official' => $official], ['-100' => 'current', '7' => 'current'], ['-100' => 'supergroup', '7' => 'private']);
    $store = new MemoryPendingBotActionStore(); $factory = new ManagementFactory(); $client = new ManagementClient(['id' => 1, 'username' => 'current']); $factory->by_bot['current'] = $client; $factory->by_token['1:secret'] = $client;
    $handler = new BotManagementHandler($current, $registry, $store, $factory, 'https://webhook.example', '1.2.3.4', fn() => 1000);
    $handler->handle(privateUpdate('/delbot')); $handler->handle(privateUpdate('1:secret', 13));
    assertTrue(str_contains($client->messages[1][1] ?? '', '@official'), 'protected bot advertised');
    assertSame(['-100'], $client->left, 'only group left');
    assertSame([], $registry->routes, 'all routes removed');
    assertTrue(!isset($registry->bots['current']), 'bot deleted');
    assertTrue($client->webhook_deleted, 'webhook deleted');
}

testAddBotConsumesTokenAndRegistersWebhook();
testProtectedBotCannotBeDeleted();
testAddBotUpdatesExistingTokenWithoutChangingIdentityOrProtection();
testUpdateTokenCommandShowsProtectedRecoveryBots();
testDeleteBotFarewellsGroupsAdvertisesProtectedBotsAndKeepsCoreState();
