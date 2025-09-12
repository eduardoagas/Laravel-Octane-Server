<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StaminaService
{
    // Cache do script Lua para chamadas estáticas
    private static ?string $consumeLuaScript = null;

    public function initializeStamina(int $now, float $maxStamina, float $agility): array
    {
        return [
            'start_time' => $now,
            'initial_stamina' => 0,
            'max_stamina' => $maxStamina,
            'dexterity' => $agility,
            'used_stamina_total' => 0,
        ];
    }

    public static function getCurrentStamina(string $battleId, string $id, string $type = 'character'): float
    {
        $field = "{$type}:{$id}";
        $key = "battle:$battleId:stamina_data";
        $data = Redis::hget($key, $field);

        Log::info("📥 [getCurrentStamina] Buscando stamina", [
            'redis_key' => $key,
            'field' => $field,
            'raw_data' => $data,
        ]);

        if (!$data) {
            Log::warning("⚠️ Nenhum dado de stamina encontrado", [
                'battleId' => $battleId,
                'field' => $field
            ]);
            return 0.0;
        }

        $parsed = json_decode($data, true);

        $startTime = (int) ($parsed['start_time'] ?? 0);
        $initial = (float) ($parsed['initial_stamina'] ?? 0.0);
        $sMax = (float) ($parsed['max_stamina'] ?? 0.0);
        $dex = max(1.0, (float) ($parsed['dexterity'] ?? 0.0));
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);

        $elapsed = now()->timestamp - $startTime;

        Log::info("📊 [getCurrentStamina] Dados extraídos:", compact('startTime', 'initial', 'sMax', 'dex', 'elapsed', 'used'));

        // ---------- constantes ----------
        $minRate = 3.60;
        $maxRate = 20.0;
        $maxDex = 300.0;
        $alpha   = 0.3;

        $absBands = [
            [0.0, 50.0, 0.4],
            [50.0, 150.0, 0.8],
            [150.0, 350.0, 1.2],
            [350.0, 700.0, 1.8],
            [700.0, PHP_FLOAT_MAX, 3.0],
        ];

        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;

        $remaining = max(0.0, (float)$elapsed);
        $currentSim = max(0.0, $initial - $used);
        $recovered = 0.0;

        foreach ($absBands as $band) {
            if ($remaining <= 0.0 || $currentSim >= $sMax) break;

            [$bFrom, $bTo, $mult] = $band;
            $bTo = min($bTo, $sMax);
            if ($currentSim >= $bTo) continue;

            $target = $bTo;
            $rate = $baseRegen * $mult;
            if ($rate <= 0.0) break;

            $need = $target - $currentSim;
            $timeToFill = $need / $rate;

            if ($timeToFill <= $remaining) {
                $recovered += $need;
                $currentSim += $need;
                $remaining -= $timeToFill;
            } else {
                $gain = $rate * $remaining;
                $recovered += $gain;
                $currentSim += $gain;
                $remaining = 0.0;
                break;
            }
        }

        if (($initial + $recovered - $used) > $sMax) {
            $recovered -= (($initial + $recovered - $used) - $sMax);
        }

        $stamina = max(0.0, min($sMax, $initial + $recovered - $used));

        Log::info("✅ [getCurrentStamina] Resultado calculado:", [
            'stamina_calculada' => $stamina,
            'stamina_limitada' => $stamina,
            'initial_stamina' => $initial,
            'recovered' => $recovered,
            'used_stamina_total' => $used,
            'elapsed_seconds' => $elapsed,
        ]);

        return $stamina;
    }

    public static function consumeStamina(string $battleId, string $id, float $amount, string $type = 'character'): ?array
    {
        $field = "{$type}:{$id}";
        $key = "battle:$battleId:stamina_data";

        if (self::$consumeLuaScript === null) {
            $luaPath = storage_path("redis_scripts/consume_stamina.lua");
            if (!file_exists($luaPath)) {
                Log::error("[consumeStamina] Lua script não encontrado em: $luaPath");
                return null;
            }
            self::$consumeLuaScript = file_get_contents($luaPath);
        }

        $nowTs = now()->timestamp;

        try {
            $raw = Redis::eval(self::$consumeLuaScript, 1, $key, $field, (string)$amount, (string)$nowTs);

            if ($raw === false) {
                Log::error("[consumeStamina] Redis::eval retornou false.", ['field' => $field, 'amount' => $amount]);
                return null;
            }

            $decoded = json_decode($raw, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::error("[consumeStamina] Falha ao decodificar JSON do Lua script", [
                    'raw' => $raw,
                    'json_last_error' => json_last_error_msg(),
                    'field' => $field,
                    'amount' => $amount,
                ]);
                return null;
            }

            if (isset($decoded['error']) && $decoded['error'] === 'insufficient') {
                Log::warning("❌ [consumeStamina] Stamina insuficiente", [
                    'field' => $field,
                    'current' => $decoded['current'] ?? null,
                    'needed' => $amount
                ]);
                return null;
            }

            Log::info("✅ [consumeStamina] Consumo aplicado", [
                'field' => $field,
                'amount' => $amount,
                'used_stamina_total' => $decoded['used'] ?? null,
                'current_after' => $decoded['current_after'] ?? null
            ]);

            return $decoded;
        } catch (\Throwable $e) {
            Log::error("[consumeStamina] Erro ao executar Lua script: " . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }
}
