<?php

namespace App\Battle\MonstersBehavior;

use Illuminate\Support\Facades\Log;

class GoblinBehavior implements MonsterBehaviorInterface
{
    public function decideAction(array $monsterData, array $battleState): ?array
    {
        $stamina = $monsterData['current_stamina'] ?? 0;

        Log::info("[GoblinBehavior] TO DECIDINDO com stamina atual $stamina");

        if ($stamina < 10) {
            return null; // pouca stamina, espera
        }

        // Escolhe a skill
        $skillId = rand(1, 100) <= 50 ? 0 : 4; // 0 = Attack, 4 = Wait
        $targetType = ($skillId == 0) ? 'enemy' : 'self';

        return [
            'caster_id' => $monsterData['instanceId'],
            'caster_type' => 'monster',
            'skill_id' => $skillId,
            'target_type' => $targetType,
            'target_id' => key($battleState['players'] ?? []), // primeiro jogador da lista
        ];
    }
}
