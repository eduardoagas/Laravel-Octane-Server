<?php

namespace App\Services;

use App\Services\Battle\BattleBroadcaster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;

class UnityConnectionRegistry
{
    protected static array $connectionsByUser = [];

    public static function register(string $userId, Connection $connection): void
    {
        Log::info("Registering user $userId with connection ID: " . $connection->id());

        // Cache local da conexão
        self::$connectionsByUser[$userId] = $connection;
    }

    public static function get(string $userId): ?Connection
    {
        return self::$connectionsByUser[$userId] ?? null;
    }

    public static function remove(Connection $connection): void
    {
        foreach (self::$connectionsByUser as $userId => $conn) {
            if ($conn === $connection) {
                Log::info("Removing connection for user $userId (ID: " . $connection->id() . ")");
                unset(self::$connectionsByUser[$userId]);
                break;
            }
        }
    }

    public static function sendToUser(int $userId, array $payload): void
    {
        if (isset(self::$connectionsByUser[$userId])) {
            Log::info("sendToUser: User $userId is local. Sending message directly.");
            self::$connectionsByUser[$userId]->send(json_encode($payload));
        } else {
            Log::info("sendToUser: User $userId NOT found locally.");
        }
    }

    public static function broadcastToBattle(string $battleId, array $payload): void
    {
        BattleBroadcaster::broadcastToBattle($battleId, $payload);
    }

    public static function logLocalConnections(): void
    {
        $userIds = array_keys(self::$connectionsByUser);
        Log::info("Currently local connected users: " . implode(', ', $userIds));
    }

    public static function broadcastToUsers(array $userIds, array $payload): void
    {
        foreach ($userIds as $userId) {
            self::sendToUser((int)$userId, $payload);
        }
    }

    public static function all(): array
    {
        Log::info("Current local connections: " . json_encode(array_keys(self::$connectionsByUser)));
        return [
            'local' => self::$connectionsByUser,
        ];
    }

    public static function dump(): void
    {
        Log::info('[UnityConnectionRegistry::dump] Dumping all active user connections:');
        foreach (self::$connectionsByUser as $userId => $connection) {
            Log::info("  User: {$userId} → Connection ID: " . $connection->id());
        }
        Log::info('[UnityConnectionRegistry::dump] End of dump.');
    }
}
