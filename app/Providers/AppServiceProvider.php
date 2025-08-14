<?php

namespace App\Providers;

use App\Battle\BattleManager;
use Laravel\Octane\Facades\Octane;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use App\Services\UnityConnectionRegistry;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /**
         * Inicializa o gerenciador de batalhas.
         * Será processado a cada X segundos pelo Octane sem bloquear o loop.
         */
        $battleManager = new BattleManager();

        // Executa a cada 5 segundos
        Octane::tick('battle-ticker', function () use ($battleManager) {
            $battleManager->processBattles();
            // $battleManager->cleanupOldBattles(3600); // Limpa batalhas paradas há > 1h
        }, 5);
    }
}
