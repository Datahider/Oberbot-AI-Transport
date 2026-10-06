<?php
declare(strict_types=1);
namespace CodexMultitenant\TransportTelegram;
use CodexMultitenant\TransportTelegram\Data\GroupAccessSetting;
use InvalidArgumentException;
use Throwable;
final class DbGroupAccessStore implements GroupAccessStoreInterface
{
    public function modeForGroup(string $chat_id): string
    {
        try { return (string) (new GroupAccessSetting(['chat_id' => $chat_id]))->mode; }
        catch (Throwable $e) { if ($e->getCode() !== -10002) throw $e; return 'admins'; }
    }
    public function setModeForGroup(string $chat_id, string $mode): void
    {
        if (!in_array($mode, ['admins', 'members'], true)) throw new InvalidArgumentException('Invalid group access mode');
        try { $setting = new GroupAccessSetting(['chat_id' => $chat_id]); }
        catch (Throwable $e) { if ($e->getCode() !== -10002) throw $e; $setting = new GroupAccessSetting(); $setting->chat_id = $chat_id; }
        $setting->mode = $mode;
        $setting->write();
    }
}
