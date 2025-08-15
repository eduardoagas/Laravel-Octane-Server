<?php

namespace App\Battle\MonstersBehavior;

use Illuminate\Support\Facades\Log;

class OrcBehavior implements MonsterBehaviorInterface
{
    public function decideAction(array $monsterData, array $battleState): ?array
    {
        $stamina = $monsterData['current_stamina'] ?? 0;

        Log::info("[OrcBehavior] TO DECIDINDO com stamina atual $stamina");

        if ($stamina < 10) {
            return null; // pouca stamina, espera
        }

        // Escolhe a skill
        $skillId = rand(1, 100) <= 50 ? 0 : 4; // 0 = Attack, 4 = Wait

        return [
            'skill_id' => $skillId,
            'target_type' => 'enemy', // alvo padrão, pode ajustar
            'target_id' => key($battleState['players'] ?? []), // primeiro jogador da lista
        ];
    }
}
