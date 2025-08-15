<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Facades\Octane;
use App\Battle\BattleManager;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton para reusar a mesma instância dentro do worker Octane
        $this->app->singleton(BattleManager::class, function ($app) {
            return new BattleManager();
        });
    }

    public function boot(): void
    {
        // Resolve a instância singleton (evita new BattleManager() em cada tick)
        $battleManager = app(BattleManager::class);

        /* // === Processa ações de jogadores a cada 2 segundos ===
        Octane::tick('battle-users-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $hasActions = $battleManager->processBattleUsers($battleId);
                if ($hasActions) {
                    Log::info("[BattleUsersTicker] Processed actions for battle $battleId");
                }
            }
        }, 2);

        // === Processa ações/comportamentos de monstros a cada 3 segundos ===
        Octane::tick('battle-monsters-ticker', function () use ($battleManager) {
            $battleIds = $battleManager->getActiveBattles();
            foreach ($battleIds as $battleId) {
                $processed = $battleManager->processBattleMonsters($battleId);
                if ($processed) {
                    Log::info("[BattleMonstersTicker] Processed monsters for battle $battleId");
                }
            }
        }, 3);*/

        // (opcional) ticker de cleanup
        // Octane::tick('battle-cleanup-ticker', function () use ($battleManager) {
        //     $battleManager->cleanupOldBattles(3600);
        // }, 10);
    }
}
