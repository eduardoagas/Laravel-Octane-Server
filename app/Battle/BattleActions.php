<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\StaminaService;
use App\Services\Battle\BattleBroadcaster;

class BattleActions
{
    /**
     * Executa a ação do monstro contra o personagem alvo.
     *
     * @param array &$monster
     * @param string $action
     * @param array &$targetCharacter
     * @param string $battleId
     * @return void
     */
    public static function executeAction(array &$monster, string $action, array &$targetCharacter, string $battleId): void
    {
        switch ($action) {
            case 'attack':
                self::attack($monster, $targetCharacter, $battleId);
                break;

            case 'wait':
                self::wait($monster);
                break;

            // Exemplo para habilidades futuras
            case 'special_skill':
                self::specialSkill($monster, $targetCharacter, $battleId);
                break;

            default:
                Log::warning("Monster {$monster['name']} performed unknown action '$action'.");
                break;
        }

        BattleBroadcaster::broadcastToBattle($battleId, [
            'monster' => $monster,
            'action' => $action,
            'targetCharacter' => $targetCharacter,
        ], 'battle_action');

        BattleBroadcaster::broadcastToBattle($battleId, [
            'players' => [
                [
                    'instanceId' => (string) $targetCharacter['id'],
                    'currentHp' => (int) $targetCharacter['hp'],
                ],
            ],
        ], 'updateYourself');
    }

    protected static function attack(array &$monster, array &$targetCharacter, string $battleId): void
    {
        $currentStamina = $monster['current_stamina'] ?? 0;
        $staminaCost = 10;

        if ($currentStamina < $staminaCost) {
            Log::info("Monster {$monster['name']} tentou atacar mas não tem stamina suficiente (tem $currentStamina, precisa de $staminaCost).");
            return; // Ou outra lógica de fallback
        }

        $damage = max(0, $monster['pattack'] - ($targetCharacter['defense'] ?? 0));
        $targetCharacter['hp'] = max(0, ($targetCharacter['hp'] ?? 0) - $damage);

        $newStamina = max(0, $currentStamina - $staminaCost);
        $monster['current_stamina'] = $newStamina;

        Log::info("Monster {$monster['name']} attacked {$targetCharacter['name']} for $damage damage. Target HP now {$targetCharacter['hp']}. Stamina after attack: $newStamina.");

        if ($targetCharacter['hp'] <= 0) {
            Log::info("Character {$targetCharacter['name']} died!");
        }

        // Atualiza stamina no serviço (persistência e controle global)
        StaminaService::consumeStamina($battleId, (string)$monster['id'], $staminaCost, 'monster');
    }

    protected static function wait(array &$monster): void
    {
        Log::info("Monster {$monster['name']} waits.");
    }

    protected static function specialSkill(array &$monster, array &$targetCharacter, string $battleId): void
    {
        // Exemplo para implementar uma habilidade especial
        Log::info("Monster {$monster['name']} uses special skill on {$targetCharacter['name']}.");
        // Implemente a lógica da skill aqui
    }
}
