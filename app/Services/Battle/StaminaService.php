<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StaminaService
{
    /**
     * Inicializa os dados de stamina de um personagem
     */
    /**
     * Inicializa os dados de stamina de um personagem
     *
     * Agora aceita opcionalmente $battleId e $field para gerar e salvar
     * o regen profile no Redis imediatamente.
     *
     * @param int $now
     * @param float $maxStamina
     * @param float $dexterity
     * @param string|null $battleId  ex: 'battle:abc' (apenas o id sem prefixo é ok também)
     * @param string|null $field     ex: "character:3" ou "monster:1"
     * @param int $step              passo para LUT (default 5)
     * @return array
     */
    public function initializeStamina(
        int $now,
        float $maxStamina,
        float $dexterity,
        ?string $battleId = null,
        ?string $field = null,
        int $step = 5
    ): array {
        $data = [
            'start_time' => $now,
            'initial_stamina' => 0.0,
            'max_stamina' => $maxStamina,
            'dexterity' => $dexterity,
            'used_stamina_total' => 0.0,
            // campos da regen diretamente
            'base_regen' => 0.0,
            'step' => $step,
            'lut' => [],
        ];

        // Se battleId e field foram informados, gera e salva o profile
        if (!empty($battleId) && !empty($field)) {
            try {
                $regenProfile = $this->buildRegenProfile($maxStamina, $dexterity, $step);

                // chave: battle:<id>:stamina_profile
                $profileKey = "battle:{$battleId}:stamina_profile";
                Redis::hset($profileKey, $field, json_encode($regenProfile, JSON_UNESCAPED_UNICODE));

                // atribui diretamente no array
                $data['base_regen'] = $regenProfile['base_regen'] ?? 0.0;
                $data['step'] = $regenProfile['step'] ?? $step;
                $data['lut'] = $regenProfile['lut'] ?? [];
            } catch (\Throwable $e) {
                Log::warning("[initializeStamina] Failed to build/save regen profile", [
                    'battle' => $battleId,
                    'field' => $field,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $data;
    }



    /**
     * Retorna a stamina atual (após regeneração) para um caster (character|monster).
     *
     * Prioridade:
     * 1) tenta usar profile salvo em Redis (battle:<battleId>:stamina_profile field {type}:{id})
     * 2) se não existir profile válido, usa computeRegenState() como fallback
     *
     * @param string $battleId
     * @param string $id instanceId
     * @param string $type 'character'|'monster'
     * @return float
     */
    public static function getCurrentStamina(string $battleId, string $id, string $type = 'character'): float
    {
        $dataKey = "battle:{$battleId}:stamina_data";
        $field = "{$type}:{$id}";
        $raw = Redis::hget($dataKey, $field);

        if (!$raw) {
            return 0.0;
        }

        $parsed = json_decode($raw, true) ?: [];
        $nowTs = now()->timestamp;

        // current before regen
        $initial = (float) ($parsed['initial_stamina'] ?? 0.0);
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);
        $sMaxFromParsed = (float) ($parsed['max_stamina'] ?? 0.0);
        $currentBefore = max(0.0, $initial - $used);

        // Try to read regen profile from Redis
        try {
            $profileKey = "battle:{$battleId}:stamina_profile";
            $profileRaw = Redis::hget($profileKey, $field);

            if ($profileRaw) {
                $profile = json_decode($profileRaw, true);
                if (is_array($profile) && !empty($profile['lut']) && is_array($profile['lut'])) {
                    // use profile
                    $elapsed = max(0, $nowTs - (int)($parsed['start_time'] ?? 0));

                    // base_regen prefer profile value, otherwise recompute using same constants as buildRegenProfile
                    if (isset($profile['base_regen'])) {
                        $baseRegen = (float) $profile['base_regen'];
                    } else {
                        // recompute baseRegen (mirror of buildRegenProfile formula)
                        $minRate = 3.6;
                        $maxRate = 20.0;
                        $maxDex = 300.0;
                        $alpha = 0.4;
                        $dex = max(1.0, (float) ($parsed['dexterity'] ?? 1.0));
                        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
                        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;
                    }

                    $lut = $profile['lut'];
                    // ensure lut sorted by stamina (ascending)
                    usort($lut, function ($a, $b) {
                        return ($a['stamina'] <=> $b['stamina']);
                    });

                    // find bounding entries for currentBefore
                    $count = count($lut);
                    if ($count === 0) {
                        // fallback
                        $state = self::computeRegenState($parsed, $nowTs);
                        return (float) ($state['current_after_regen'] ?? 0.0);
                    }

                    // If currentBefore is below first entry or above last entry, clamp
                    $first = $lut[0];
                    $last = $lut[$count - 1];

                    if ($currentBefore <= (float)$first['stamina']) {
                        $mult = (float)$first['mult'];
                    } elseif ($currentBefore >= (float)$last['stamina']) {
                        $mult = (float)$last['mult'];
                    } else {
                        // find k where lut[k]['stamina'] >= currentBefore
                        $k = 0;
                        for ($i = 0; $i < $count; $i++) {
                            if ((float)$lut[$i]['stamina'] >= $currentBefore) {
                                $k = $i;
                                break;
                            }
                        }
                        // ensure k>0 (we already handled <= first)
                        if ($k <= 0) {
                            $mult = (float)$lut[0]['mult'];
                        } else {
                            $lo = $lut[$k - 1];
                            $hi = $lut[$k];
                            $sLo = (float)$lo['stamina'];
                            $sHi = (float)$hi['stamina'];
                            $mLo = (float)$lo['mult'];
                            $mHi = (float)$hi['mult'];

                            // avoid division by zero
                            if ($sHi <= $sLo) {
                                $mult = $mLo;
                            } else {
                                $t = ($currentBefore - $sLo) / ($sHi - $sLo);
                                $mult = $mLo + ($mHi - $mLo) * $t;
                            }
                        }
                    }

                    $recovered = $elapsed * $baseRegen * (float)$mult;
                    $currentAfter = min($sMaxFromParsed, $currentBefore + $recovered);

                    return (float)$currentAfter;
                }
            }
        } catch (\Throwable $e) {
            // se algo deu errado ao ler/decodificar profile, log e fallback para computeRegenState
            Log::warning("[StaminaService@getCurrentStamina] Failed to use regen profile, falling back. Error: " . $e->getMessage(), [
                'battle' => $battleId,
                'field' => $field
            ]);
        }

        // fallback: usar computeRegenState (comportamento anterior)
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
    /**
     * Consome ou recupera stamina (substitui o script Lua).
     *
     * Agora prefere usar o profile salvo em Redis (battle:<battleId>:stamina_profile field {type}:{id})
     * para calcular currentAfterRegen via LUT + interpola. Se não houver profile válido, usa computeRegenState().
     *
     * @param string $battleId
     * @param string $id instanceId do caster
     * @param float $amount >0 consome, 0 compacta, <0 recupera
     * @param string $type 'character'|'monster'
     * @return array|null Retorna array similar ao Lua (['used'=>..., 'current_after'=>..., ...]) ou null se insuficiente / erro
     */
    public static function consumeStamina(string $battleId, string $id, float $amount, string $type = 'character'): ?array
    {
        $dataKey = "battle:{$battleId}:stamina_data";
        $field = "{$type}:{$id}";
        $nowTs = now()->timestamp;

        // Ler estado existente
        $raw = Redis::hget($dataKey, $field);
        if (!$raw) {
            // comportamento compatível: sem dados -> null
            return null;
        }

        $parsed = json_decode($raw, true) ?: [];

        // valores básicos
        $initial = (float) ($parsed['initial_stamina'] ?? 0.0);
        $used = (float) ($parsed['used_stamina_total'] ?? 0.0);
        $sMax = (float) ($parsed['max_stamina'] ?? 0.0);
        $currentBefore = max(0.0, $initial - $used);

        // Tentativa de usar profile do Redis para calcular currentAfterRegen
        $currentAfterRegen = null;
        try {
            $profileKey = "battle:{$battleId}:stamina_profile";
            $profileRaw = Redis::hget($profileKey, $field);

            if ($profileRaw) {
                $profile = json_decode($profileRaw, true);
                if (is_array($profile) && !empty($profile['lut']) && is_array($profile['lut'])) {
                    $elapsed = max(0, $nowTs - (int)($parsed['start_time'] ?? 0));

                    // base_regen prefer profile value, caso contrário recomputa
                    if (isset($profile['base_regen'])) {
                        $baseRegen = (float) $profile['base_regen'];
                    } else {
                        $minRate = 3.6;
                        $maxRate = 20.0;
                        $maxDex = 300.0;
                        $alpha = 0.4;
                        $dex = max(1.0, (float) ($parsed['dexterity'] ?? 1.0));
                        $agiFactor = pow(min($dex / $maxDex, 1.0), $alpha);
                        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor;
                    }

                    $lut = $profile['lut'];
                    usort($lut, function ($a, $b) {
                        return ($a['stamina'] <=> $b['stamina']);
                    });

                    $count = count($lut);
                    if ($count > 0) {
                        $first = $lut[0];
                        $last = $lut[$count - 1];

                        if ($currentBefore <= (float)$first['stamina']) {
                            $mult = (float)$first['mult'];
                        } elseif ($currentBefore >= (float)$last['stamina']) {
                            $mult = (float)$last['mult'];
                        } else {
                            // encontra índice k tal que lut[k].stamina >= currentBefore
                            $k = 0;
                            for ($i = 0; $i < $count; $i++) {
                                if ((float)$lut[$i]['stamina'] >= $currentBefore) {
                                    $k = $i;
                                    break;
                                }
                            }
                            if ($k <= 0) {
                                $mult = (float)$lut[0]['mult'];
                            } else {
                                $lo = $lut[$k - 1];
                                $hi = $lut[$k];
                                $sLo = (float)$lo['stamina'];
                                $sHi = (float)$hi['stamina'];
                                $mLo = (float)$lo['mult'];
                                $mHi = (float)$hi['mult'];

                                if ($sHi <= $sLo) {
                                    $mult = $mLo;
                                } else {
                                    $t = ($currentBefore - $sLo) / ($sHi - $sLo);
                                    $mult = $mLo + ($mHi - $mLo) * $t;
                                }
                            }
                        }

                        $recovered = $elapsed * $baseRegen * (float)$mult;
                        $currentAfterRegen = min($sMax, $currentBefore + $recovered);
                    }
                }
            }
        } catch (\Throwable $e) {
            // log e deixamos currentAfterRegen = null para cair no fallback
            Log::warning("[StaminaService@consumeStamina] regen profile read failed, fallback. Error: " . $e->getMessage(), [
                'battle' => $battleId,
                'field' => $field
            ]);
        }

        // Fallback: se profile não foi usado/produziu valor, use computeRegenState
        if ($currentAfterRegen === null) {
            $state = self::computeRegenState($parsed, $nowTs);
            $currentAfterRegen = (float) ($state['current_after_regen'] ?? 0.0);
        }

        // RECUPERAÇÃO (amount < 0)
        if ($amount < 0.0) {
            $recoverAmount = -$amount;
            $new_current = min($sMax, $currentAfterRegen + $recoverAmount);
            $parsed['initial_stamina'] = $new_current;
            $parsed['start_time'] = $nowTs;
            $parsed['used_stamina_total'] = 0.0;
            Redis::hset($dataKey, $field, json_encode($parsed, JSON_UNESCAPED_UNICODE));

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
            Redis::hset($dataKey, $field, json_encode($parsed, JSON_UNESCAPED_UNICODE));

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
        Redis::hset($dataKey, $field, json_encode($parsed, JSON_UNESCAPED_UNICODE));

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
        $alpha = 0.4;

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
            $mults[] = (float)$bandMult;
            // $lut[] = ['stamina' => round($cur, 2), 'mult' => (float)$bandMult];
        }
        // se o for terminou sem exatamente atingir maxS, garante entry final
        // garantir entrada final em maxStamina caso o loop não tenha incluído exato
        if (empty($mults) || count($mults) === 0 || end($mults) !== (float)$bandMult) {
            // pega bandMult para maxS
            $bandMult = 1.0;
            foreach ($bands as [$from, $to, $m]) {
                $bTo = min($to, $maxS);
                if ($maxS >= $from && $maxS <= $bTo) {
                    $bandMult = $m;
                    break;
                }
            }
            $mults[] = (float)$bandMult;
        }

        return [
            'base_regen' => $baseRegen,
            'step' => $step,
            'max_stamina' => $maxS,
            'lut' => $mults, // <- agora é array de floats
            'generated_at' => now()->timestamp,
        ];
    }
}
