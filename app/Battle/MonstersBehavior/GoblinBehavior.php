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

        // Busca as skills do monstro (passadas no battleState)
        $monsterSkills = $battleState['skills'] ?? [];

        if (empty($monsterSkills)) {
            Log::warning("[GoblinBehavior] Monster não possui skills carregadas");
            return null;
        }

        // Escolhe Attack ou Wait dinamicamente
        $attackSkill = null;
        $waitSkill = null;

        foreach ($monsterSkills as $skill) {
            if (strtolower($skill['name']) === 'attack') $attackSkill = $skill;
            if (strtolower($skill['name']) === 'wait') $waitSkill = $skill;
        }

        // Se não achar Attack ou Wait, pega a primeira skill disponível
        if (!$attackSkill) $attackSkill = $monsterSkills[0];
        if (!$waitSkill) $waitSkill = $monsterSkills[0];

        // Decide qual skill usar (50% Attack, 50% Wait)
        $chosenSkill = rand(1, 100) <= 50 ? $attackSkill : $waitSkill;
        $targetType = ($chosenSkill['name'] === 'Attack') ? 'enemy' : 'self';

        // Define target_id se for inimigo
        $targetId = null;
        if ($targetType === 'enemy' && !empty($battleState['players'])) {
            $firstPlayer = reset($battleState['players']); // pega o primeiro jogador da lista
            $targetId = $firstPlayer['instanceId'] ?? key($battleState['players']);
        }

        return [
            'caster_id' => $monsterData['instanceId'],
            'caster_type' => 'monster',
            'skill_id' => $chosenSkill['id'],
            'target_type' => $targetType,
            'target_id' => $targetId,
        ];
    }
}
