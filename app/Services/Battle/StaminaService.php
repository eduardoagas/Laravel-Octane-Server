<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StaminaService
{
    private static ?string $consumeLuaScript = null;

    /**
     * Inicializa os dados de stamina de um personagem
     */
    public function initializeStamina(int $now, float $maxStamina, float $dexterity): array
    {
        return [
            'start_time' => $now,
            'initial_stamina' => 0.0,
            'max_stamina' => $maxStamina,
            'dexterity' => $dexterity,
            'used_stamina_total' => 0.0,
        ];
    }

    /**
     * Calcula a stamina atual considerando tempo decorrido e regeneração
     * Usando a lógica contínua do Lua consume_stamina_continuous.lua
     */
    public static function getCurrentStamina(string $battleId, string $id, string $type = 'character'): float
    {
        $key = "battle:$battleId:stamina_data";
        $field = "{$type}:{$id}";
        $data = Redis::hget($key, $field);
        if (!$data) return 0.0;

        $parsed = json_decode($data, true);

        $startTime = (int) ($parsed['start_time'] ?? 0);
        $initial = (float) ($parsed['initial_stamina'] ?? 0.0);
        $sMax = (float) ($parsed['max_stamina'] ?? 0.0);
        $dex = max(1.0, (float) ($parsed['dexterity'] ?? 1.0));
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);

        $nowTs = now()->timestamp;
        $elapsed = max(0.0, $nowTs - $startTime);

        // ---------- constantes ----------
        $minRate = 3.6;
        $maxRate = 20.0;
        $maxDex = 300.0;
        $alpha = 0.3;

        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;

        // stamina atual antes do consumo
        $current = max(0.0, $initial - $used);

        // multiplicador contínuo linear (0.4 a 3.0)
        $fraction = min($current / $sMax, 1.0);
        $mult = 0.4 + $fraction * (3.0 - 0.4);
        $recovered = $elapsed * $baseRegen * $mult;

        $current = min($sMax, $current + $recovered);

        return $current;
    }

    /**
     * Consome stamina via Lua script seguro
     */
    public static function consumeStamina(string $battleId, string $id, float $amount, string $type = 'character'): ?array
    {
        $key = "battle:$battleId:stamina_data";
        $field = "{$type}:{$id}";

        if (self::$consumeLuaScript === null) {
            $luaPath = storage_path("redis_scripts/consume_stamina.lua");
            if (!file_exists($luaPath)) return null;
            self::$consumeLuaScript = file_get_contents($luaPath);
        }

        $nowTs = now()->timestamp;

        try {
            $raw = Redis::eval(self::$consumeLuaScript, 1, $key, $field, (string)$amount, (string)$nowTs);
            if (!$raw) return null;

            $decoded = json_decode($raw, true);
            if (!$decoded || (isset($decoded['error']) && $decoded['error'] === 'insufficient')) {
                return null;
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::error("[consumeStamina] Erro ao executar Lua script: " . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }
}
