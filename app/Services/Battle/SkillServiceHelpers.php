<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class SkillServiceHelpers extends SkillService
{
    /**
     * Retorna agiFactor usado no consume_stamina.lua (alpha = 0.3)
     */
    private function agiFactorFromDex(int $dex): float
    {
        $maxDex = 300.0;
        $alpha = 0.3;
        $clamped = max(0.0, min($dex / $maxDex, 1.0));
        return pow($clamped, $alpha);
    }

    /**
     * Mapeia dex -> potencial attack_rate (sem limite por stamina).
     * Útil para mostrar "potencial" ou calcular DPS burst.
     * Range defaults: 0.5 .. 2.0 atk/s
     */
    private function mappedAttackRateFromDex(int $dex, float $minAtkRate = 0.5, float $maxAtkRate = 2.0): float
    {
        $agiFactor = $this->agiFactorFromDex($dex);
        return $minAtkRate + ($maxAtkRate - $minAtkRate) * $agiFactor;
    }

    /**
     * Attack rate sustentável considerando regen de stamina (baseRegen),
     * usando exatamente os mesmos parâmetros do consume_stamina.lua.
     * Retorna a taxa final (attacks/sec) que pode ser mantida indefinidamente.
     *
     * @param int $dex
     * @param int|null $staminaCost stamina cost por ataque (se null, não limita)
     */
    private function sustainableAttackRateFromDex(int $dex, ?int $staminaCost = null, float $minAtkRate = 0.5, float $maxAtkRate = 2.0): float
    {
        // constants from consume_stamina.lua
        $minRate = 3.60;
        $maxRate = 20.0;
        $maxDex = 300.0;
        $alpha = 0.3;

        $clamped = max(0.0, min($dex / $maxDex, 1.0));
        $agiFactor = pow($clamped, $alpha);

        $baseRegen = $minRate + ($maxRate - $minRate) * $agiFactor; // stamina/sec
        $mapped = $minAtkRate + ($maxAtkRate - $minAtkRate) * $agiFactor;

        if ($staminaCost === null || $staminaCost <= 0) {
            return round($mapped, 6);
        }

        $sustainable = $baseRegen / (float)$staminaCost;
        $final = min($mapped, $sustainable);
        $final = max(0.05, $final); // safety floor
        return round($final, 6);
    }





    
}
