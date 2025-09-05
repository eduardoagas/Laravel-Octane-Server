<?php

namespace App\Providers;

use App\Battle\BattleManager;
use Laravel\Octane\Facades\Octane;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $battleManager = new BattleManager();
        Octane::tick('battle-state-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $hasActions = $battleManager->processBattleStateSync($battleId);
                if ($hasActions) {
                    \Illuminate\Support\Facades\Log::info("[BattleStateTicker] Processed state syncs for battle $battleId");
                }
            }
        }, 0.9);
        // === Processa ações de jogadores a cada 2 segundos ===
        Octane::tick('battle-users-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $hasActions = $battleManager->processBattleUsers($battleId);
                if ($hasActions) {
                    \Illuminate\Support\Facades\Log::info("[BattleUsersTicker] Processed actions for battle $battleId");
                }
            }
        }, 0.1);

        // === Processa ações/comportamentos de monstros a cada 3 segundos ===
        Octane::tick('battle-monsters-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $processed = $battleManager->processBattleMonsters($battleId);
                if ($processed) {
                    \Illuminate\Support\Facades\Log::info("[BattleMonstersTicker] Processed monsters for battle $battleId");
                }
            }
        }, 0.1);

        // === Processa buffs e debuffs, ticks de 1 segundo ===
        Octane::tick('battle-effects-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $processed = $battleManager->processBattleEffects($battleId);
                if ($processed) {
                    \Illuminate\Support\Facades\Log::info("[BattleEffectsTicker] Processed effects for battle $battleId");
                }
            }
        }, 1.0);

        // === Processa skills pendentes ===
        Octane::tick('battle-skills-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $processed = $battleManager->processBattlePendingSkills($battleId);
                if ($processed) {
                    \Illuminate\Support\Facades\Log::info("[BattleSkillsTicker] Processed skills for battle $battleId");
                }
            }
        }, 0.2);
        // === Limpa batalhas antigas a cada 10 segundos ===
        /*Octane::tick('battle-cleanup-ticker', function () use ($battleManager) {
            $battleManager->cleanupOldBattles(3600);
        }, 10);*/
    }
}
