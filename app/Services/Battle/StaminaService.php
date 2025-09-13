<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StaminaService
{
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
     * Retorna a stamina atual (após regeneração) para um caster (character|monster).
     *
     * @param string $battleId
     * @param string $id instanceId
     * @param string $type 'character'|'monster'
     * @return float
     */
    public static function getCurrentStamina(string $battleId, string $id, string $type = 'character'): float
    {
        $key = "battle:{$battleId}:stamina_data";
        $field = "{$type}:{$id}";
        $data = Redis::hget($key, $field);

        if (!$data) return 0.0;

        $parsed = json_decode($data, true) ?: [];

        $nowTs = now()->timestamp;
        $state = self::computeRegenState($parsed, $nowTs);

        return (float) ($state['current_after_regen'] ?? 0.0);
    }

    /**
     * Consome ou recupera stamina (substitui o script Lua).
     *
     * @param string $battleId
     * @param string $id instanceId do caster
     * @param float $amount >0 consome, 0 compacta, <0 recupera
     * @param string $type 'character'|'monster'
     * @return array|null Retorna array similar ao Lua (['used'=>..., 'current_after'=>..., ...]) ou null se insuficiente / erro
     */
    public static function consumeStamina(string $battleId, string $id, float $amount, string $type = 'character'): ?array
    {
        $key = "battle:{$battleId}:stamina_data";
        $field = "{$type}:{$id}";
        $nowTs = now()->timestamp;

        // Ler estado existente
        $raw = Redis::hget($key, $field);
        if (!$raw) {
            // comportamento compatível: sem dados -> null
            return null;
        }

        $parsed = json_decode($raw, true) ?: [];

        // calcula estado regenerado
        $state = self::computeRegenState($parsed, $nowTs);
        $currentAfterRegen = (float) ($state['current_after_regen'] ?? 0.0);
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);

        // RECUPERAÇÃO (amount < 0)
        if ($amount < 0.0) {
            $recoverAmount = -$amount;
            $new_current = min((float)($parsed['max_stamina'] ?? 0.0), $currentAfterRegen + $recoverAmount);
            $parsed['initial_stamina'] = $new_current;
            $parsed['start_time'] = $nowTs;
            $parsed['used_stamina_total'] = 0.0;
            Redis::hset($key, $field, json_encode($parsed, JSON_UNESCAPED_UNICODE));

            return [
                'used' => $used,
                'recovered' => $recoverAmount,
                'current_after' => $new_current,
                'note' => 'recovered',
            ];
        }

        // ZERO: compacta estado (amount == 0)
        if ($amount == 0.0) {
            $parsed['initial_stamina'] = $currentAfterRegen;
            $parsed['start_time'] = $nowTs;
            $parsed['used_stamina_total'] = 0.0;
            Redis::hset($key, $field, json_encode($parsed, JSON_UNESCAPED_UNICODE));

            return [
                'used' => $used,
                'current_after' => $currentAfterRegen,
                'note' => 'zero_cost',
            ];
        }

        // CONSUMO (amount > 0)
        if ($currentAfterRegen < $amount) {
            // insuficiente -> retornar null (compatível com comportamento anterior)
            return null;
        }

        $new_used = $used + $amount;
        $current_after = max(0.0, $currentAfterRegen - $amount);

        // compacta estado (mesma estratégia do Lua)
        $parsed['initial_stamina'] = $current_after;
        $parsed['start_time'] = $nowTs;
        $parsed['used_stamina_total'] = 0.0;
        Redis::hset($key, $field, json_encode($parsed, JSON_UNESCAPED_UNICODE));

        return [
            'used' => $new_used,
            'current_after' => $current_after,
        ];
    }

    /**
     * Helper reutilizável que calcula o estado de regeneração.
     *
     * Entrada: $parsed é o array com chaves:
     *  - start_time, initial_stamina, max_stamina, dexterity, used_stamina_total
     *
     * Saída (array):
     *  - elapsed
     *  - base_regen
     *  - mult
     *  - recovered
     *  - current_before (initial - used)
     *  - current_after_regen (min(max_stamina, current_before + recovered))
     *
     * @param array $parsed
     * @param int $nowTs
     * @return array
     */
    private static function computeRegenState(array $parsed, int $nowTs): array
    {
        $start_time = (int) ($parsed['start_time'] ?? 0);
        $initial = (float) ($parsed['initial_stamina'] ?? 0.0);
        $sMax = (float) ($parsed['max_stamina'] ?? 0.0);
        $dex = max(1.0, (float) ($parsed['dexterity'] ?? 1.0));
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);

        // constantes (mesmas do lua)
        $minRate = 3.6;
        $maxRate = 20.0;
        $maxDex = 300.0;
        $alpha = 0.3;

        // bandas (mesmas do lua)
        $bands = [
            [0.0, 50.0, 1.0],
            [50.0, 150.0, 1.2],
            [150.0, 350.0, 1.4],
            [350.0, 700.0, 1.8],
            [700.0, PHP_FLOAT_MAX, 3.0],
        ];

        $elapsed = max(0, $nowTs - $start_time);

        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;

        $current = max(0.0, $initial - $used);

        // multiplicador interpolado
        $mult = 0.0;
        foreach ($bands as [$from, $to, $bandMult]) {
            if ($current >= $from && $current <= $to) {
                $fraction = ($to - $from) > 0.0 ? (($current - $from) / ($to - $from)) : 0.0;
                $mult = $bandMult * (0.4 + 0.6 * $fraction);
                break;
            }
        }

        $recovered = $elapsed * $baseRegen * $mult;
        $currentAfterRegen = min($sMax, $current + $recovered);

        return [
            'elapsed' => $elapsed,
            'base_regen' => $baseRegen,
            'mult' => $mult,
            'recovered' => $recovered,
            'current_before' => $current,
            'current_after_regen' => $currentAfterRegen,
        ];
    }
}
