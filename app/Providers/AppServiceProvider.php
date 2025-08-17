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

        // === Processa ações de jogadores a cada 2 segundos ===
        Octane::tick('battle-users-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $hasActions = $battleManager->processBattleUsers($battleId);
                if ($hasActions) {
                    \Illuminate\Support\Facades\Log::info("[BattleUsersTicker] Processed actions for battle $battleId");
                }
            }
        }, 0.5);

        // === Processa ações/comportamentos de monstros a cada 3 segundos ===
        Octane::tick('battle-monsters-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $processed = $battleManager->processBattleMonsters($battleId);
                if ($processed) {
                    \Illuminate\Support\Facades\Log::info("[BattleMonstersTicker] Processed monsters for battle $battleId");
                }
            }
        }, 3);

        // === Limpa batalhas antigas a cada 10 segundos ===
        /*Octane::tick('battle-cleanup-ticker', function () use ($battleManager) {
            $battleManager->cleanupOldBattles(3600);
        }, 10);*/
    }
}
