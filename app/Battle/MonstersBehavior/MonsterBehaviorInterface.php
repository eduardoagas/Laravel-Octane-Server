<?php

namespace App\Battle\MonstersBehavior;

interface MonsterBehaviorInterface {
    /**
     * Decide a ação do monstro para esta rodada.
     *
     * @param array $monsterData Dados do monstro (stamina, tipo, etc)
     * @param array $battleState Estado atual da batalha (monstros, jogadores, etc)
     * @return string|null Retorna o nome da ação ou null para "esperar"
     */
    public function decideAction(array $monsterData, array $battleState, float $currentStamina): ?array;
}
