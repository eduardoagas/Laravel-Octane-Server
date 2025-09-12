<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StaminaService
{
    private static ?string $staminaLogicScriptSha = null;

    /**
     * Cria e armazena os dados iniciais de stamina no Redis.
     *
     * @param string $battleId
     * @param string $id
     * @param float $maxStamina
     * @param float $dexterity
     * @param string $type
     * @return bool
     */
    public static function createStaminaData(string $battleId, string $id, float $maxStamina, float $dexterity, string $type = 'character'): array
    {

        $initialData = [
            'start_time' => now()->timestamp,
            'initial_stamina' => 0,
            'max_stamina' => $maxStamina,
            'dexterity' => $dexterity,
            'used_stamina_total' => 0,
        ];


        return $initialData;
    }

    /**
     * Aplica uma alteração de stamina usando o script Lua.
     *
     * @param string $battleId
     * @param string $id
     * @param float $amount
     * @param string $type
     * @return array|null
     */
    public static function applyStaminaChange(string $battleId, string $id, int $amount, string $type = 'character'): ?array
    {
        $field = "{$type}:{$id}";
        $key = "battle:$battleId:stamina_data";

        if (self::$staminaLogicScriptSha === null) {
            $luaPath = storage_path("redis_scripts/stamina_logic.lua");
            if (!file_exists($luaPath)) {
                Log::error("[StaminaService] Lua script não encontrado em: $luaPath");
                return null;
            }
            $script = file_get_contents($luaPath);
            self::$staminaLogicScriptSha = Redis::script('load', $script);
        }

        $nowTs = now()->timestamp;

        try {
            $raw = Redis::evalsha(self::$staminaLogicScriptSha, 1, $key, $field, (string)$amount, (string)$nowTs);

            if ($raw === false) {
                Log::error("[StaminaService] Redis::evalsha retornou false.", compact('field', 'amount'));
                return null;
            }

            $decoded = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("[StaminaService] Falha ao decodificar JSON do Lua script", [
                    'raw' => $raw,
                    'json_last_error' => json_last_error_msg(),
                    'field' => $field,
                ]);
                return null;
            }

            if (isset($decoded['error'])) {
                Log::warning("❌ [StaminaService] Erro retornado pelo script Lua", [
                    'field' => $field,
                    'error' => $decoded['error'],
                    'message' => $decoded['message'] ?? null,
                ]);
                return null;
            }

            Log::info("✅ [StaminaService] Operação de stamina aplicada", [
                'field' => $field,
                'amount' => $amount,
                'result' => $decoded,
            ]);

            return $decoded;
        } catch (\Throwable $e) {
            Log::error("[StaminaService] Erro ao executar Lua script: " . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    public static function getCurrentStamina(string $battleId, string $id, string $type = 'character'): float
    {
        $result = self::applyStaminaChange($battleId, $id, 0, $type);
        return $result['current_after'] ?? 0.0;
    }

    public static function consumeStamina(string $battleId, string $id, int $amount, string $type = 'character'): ?array
    {
        return self::applyStaminaChange($battleId, $id, $amount, $type);
    }
}
