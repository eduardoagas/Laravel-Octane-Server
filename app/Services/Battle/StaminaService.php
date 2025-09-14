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

        // constantes
        $minRate = 3.6;
        $maxRate = 20.0;
        $maxDex = 300.0;
        $alpha = 0.4;

        // bandas iguais ao antigo
        $bands = [
            [0.0, 150.0, 0.50],
            [150.0, 350.0, 0.55],
            [350.0, 700.0, 0.65],
            [700.0, PHP_FLOAT_MAX, 0.7],
        ];

        $elapsed = max(0, $nowTs - $start_time);

        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;

        $remaining = (float) $elapsed;
        $currentSim = max(0.0, $initial - $used);
        $recovered = 0.0;

        foreach ($bands as [$bFrom, $bTo, $bandMult]) {
            if ($remaining <= 0.0 || $currentSim >= $sMax) break;

            $bTo = min($bTo, $sMax);
            if ($currentSim >= $bTo) continue;

            $target = $bTo;
            $rate = $baseRegen * $bandMult;
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

        $currentAfterRegen = min($sMax, $currentSim);

        return [
            'elapsed' => $elapsed,
            'base_regen' => $baseRegen,
            'recovered' => $recovered,
            'current_before' => max(0.0, $initial - $used),
            'current_after_regen' => $currentAfterRegen,
        ];
    }

    /**
     * Gera um perfil de regen (lookup table) para um personagem/monstro.
     *
     * @param float $maxStamina
     * @param float $dex
     * @param int $step passo da LUT (ex: 5 ou 10). Menor = mais precisão.
     * @return array [
     *   'base_regen' => float,
     *   'step' => int,
     *   'max_stamina' => float,
     *   'lut' => [ ['stamina' => float, 'mult' => float], ... ]
     * ]
     */
    public function buildRegenProfile(float $maxStamina, float $dex, int $step = 5): array
    {
        // constantes idênticas às usadas no computeRegenState
        $minRate = 3.6;
        $maxRate = 20.0;
        $maxDex = 300.0;
        $alpha = 0.3;

        // bandas (use as bandas que você já usa)
        $bands = [
            [0.0, 50.0, 0.4],
            [50.0, 150.0, 0.8],
            [150.0, 350.0, 1.2],
            [350.0, 700.0, 1.8],
            [700.0, PHP_FLOAT_MAX, 3.0],
        ];

        $dex = max(1.0, (float)$dex);
        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;

        $lut = [];
        $step = max(1, (int)$step);
        $maxS = max(0.0, (float)$maxStamina);
        // garantir que incluímos exatamente maxStamina no final
        for ($s = 0.0; $s <= $maxS; $s += $step) {
            $cur = $s;
            // encontra banda aplicável
            $bandMult = 1.0;
            foreach ($bands as [$from, $to, $m]) {
                $bTo = min($to, $maxS);
                if ($cur >= $from && $cur <= $bTo) {
                    $bandMult = $m;
                    break;
                }
            }
            $lut[] = ['stamina' => round($cur, 2), 'mult' => (float)$bandMult];
        }
        // se o for terminou sem exatamente atingir maxS, garante entry final
        $last = end($lut);
        if (!$last || $last['stamina'] < $maxS) {
            $bandMult = 1.0;
            foreach ($bands as [$from, $to, $m]) {
                $bTo = min($to, $maxS);
                if ($maxS >= $from && $maxS <= $bTo) {
                    $bandMult = $m;
                    break;
                }
            }
            $lut[] = ['stamina' => round($maxS, 2), 'mult' => (float)$bandMult];
        }

        return [
            'base_regen' => $baseRegen,
            'step' => $step,
            'max_stamina' => $maxS,
            'lut' => $lut,
            'generated_at' => now()->timestamp,
        ];
    }
}
