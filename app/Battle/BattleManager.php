<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\BattleActions;
use App\Services\Battle\StaminaService;
use App\Services\Battle\BattleBroadcaster;
use App\Battle\BattleManagerHelpers;
use App\Battle\BattleActions as BattleBattleActions;

class BattleManager extends BattleManagerHelpers
{
    /* public function createBattle(string $battleId, array $battleData): void
    {
        Log::info("Creating battle $battleId with data:", ['battleData' => $battleData]);

        foreach ($battleData['monsters'] as $index => $monster) {
            Redis::hset("battle:$battleId:monsters", $index, json_encode($monster));
        }

        foreach ($battleData['characters'] as $charId => $character) {
            Redis::hset("battle:$battleId:characters_data", $charId, json_encode($character));
        }

        Redis::sadd('battles:active', $battleId);
        $this->updateLastUpdate($battleId);

        Log::info("Battle $battleId created and added to active battles.");
    }*/

    /**
     * Processa efeitos (buffs e debuffs) tanto de characters quanto de monsters.
     *
     * Estratégia unificada:
     * - Usa instanceIds para characters (set: battle:<id>:characters_instances)
     * - Lê monsters via HGETALL("battle:<id>:monsters") e itera suas instanceIds
     * - Para cada debuff/buff:
     *    - decrementa duration (ticks)
     *    - remove quando expira (e faz cleanup do tick counter)
     *    - persiste nova duração
     *    - se existir 'skill_id' e 'tick_interval', incrementa contador e chama SkillService::applySkill
     *      - resolve caster usando caster_id + caster_type armazenados no debuff/buff, com fallback para o alvo
     *
     * Observações:
     * - Espera que debuffs/buffs contenham, idealmente: caster_id, caster_type, duration, skill_id (opcional), tick_interval (opcional)
     * - Mantém consistência com o uso de instanceIds em todo o BattleManager
     */
    public function processBattleEffects(string $battleId): bool
    {
        // já não precisamos injetar SkillService aqui para a execução — BattleActions usa internamente
        $processedAny = false;

        // ------------- PROCESSA CHARACTERS (por instanceId) -------------
        $charInstanceIds = Redis::smembers("battle:$battleId:characters_instances") ?: [];

        foreach ($charInstanceIds as $instanceId) {
            // lê a entidade atual e seus stats (pode ser string JSON ou array)
            $charJson = Redis::hget("battle:$battleId:characters_data", $instanceId);
            $charEntity = $charJson ? json_decode($charJson, true) : null;
            $charStats = $charEntity['stats'] ?? [];
            if (is_string($charStats)) $charStats = json_decode($charStats, true) ?: [];

            // tratar tanto debuffs quanto buffs
            $processed = $this->processEffectsForEntity(
                $battleId,
                'character',
                $instanceId,
                $charStats
            );

            if ($processed) $processedAny = true;
        }

        // ------------- PROCESSA MONSTERS (por instanceId chave do hash) -------------
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters") ?: [];
        foreach ($monstersRaw as $monsterInstanceId => $monsterJson) {
            $monsterEntity = $monsterJson ? json_decode($monsterJson, true) : null;
            $monsterStats = $monsterEntity['stats'] ?? [];
            if (is_string($monsterStats)) $monsterStats = json_decode($monsterStats, true) ?: [];

            $processed = $this->processEffectsForEntity(
                $battleId,
                'monster',
                (string)$monsterInstanceId,
                $monsterStats
            );

            if ($processed) $processedAny = true;
        }

        return $processedAny;
    }


    /**
     * Helper que processa buffs/debuffs de uma única entidade (character ou monster).
     *
     * - $entityType: 'character' ou 'monster'
     * - $instanceId: instanceId na batalha
     * - $stats: array decodificado de stats (pelo menos para compor o caster/target payloads)
     */
    /**
     * Helper que processa buffs/debuffs de uma única entidade (character ou monster).
     *
     * - $entityType: 'character' ou 'monster'
     * - $instanceId: instanceId na batalha
     * - $stats: array decodificado de stats (pelo menos para compor o caster/target payloads)
     */
    private function processEffectsForEntity(
        string $battleId,
        string $entityType,
        string $instanceId,
        array $stats
    ): bool {
        $processed = false;
        $isCharacter = $entityType === 'character';
        $entityPrefix = $isCharacter ? "character" : "monster";
        $baseKey = "battle:$battleId:{$entityPrefix}:{$instanceId}";

        // --- Determine debuffs key: prefer per-instance, fallback to shared monsters:debuffs ---
        $perInstanceDebuffsKey = $baseKey . ":debuffs";
        $sharedMonstersDebuffsKey = "battle:$battleId:monsters:debuffs";
        if ($isCharacter) {
            $debuffsKey = $perInstanceDebuffsKey;
            $useSharedDebuffs = false;
        } else {
            if (Redis::exists($perInstanceDebuffsKey)) {
                $debuffsKey = $perInstanceDebuffsKey;
                $useSharedDebuffs = false;
            } else {
                $debuffsKey = $sharedMonstersDebuffsKey;
                $useSharedDebuffs = true;
            }
        }

        $debuffs = Redis::hgetall($debuffsKey) ?: [];

        // sinaliza se após aplicar ticks precisamos checar fim de batalha
        $needCheckBattleEnd = false;

        foreach ($debuffs as $field => $json) {
            // when using shared hash for monsters, only handle fields for this instance
            if ($useSharedDebuffs) {
                // fields format expected: "<instanceId>:<stat>:<casterId>"
                if (strpos((string)$field, $instanceId . ':') !== 0) {
                    continue; // not for this instance
                }
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                Redis::hdel($debuffsKey, $field);
                Log::warning("[BattleEffects] Debuff JSON inválido for {$entityType} {$instanceId} field {$field} (key {$debuffsKey})");
                $processed = true;
                continue;
            }

            // duration: only decrement if set and not null (permanent = null stays)
            if (isset($data['duration']) && $data['duration'] !== null) {
                $data['duration'] = (int)$data['duration'] - 1;
                if ($data['duration'] <= 0) {
                    Redis::hdel($debuffsKey, $field);
                    Log::info("[BattleEffects] Removed expired debuff {$field} from {$entityType} {$instanceId} (key {$debuffsKey})");
                    $processed = true;
                    continue;
                }
            }

            // tick logic (tick_count stored inside the debuff JSON)
            $tickCount = (int)($data['tick_count'] ?? 0);
            $interval = isset($data['tick_interval']) ? (int)$data['tick_interval'] : 1;
            $skillId = isset($data['tick_skill_id']) ? (int)$data['tick_skill_id'] : null;

            if ($skillId) {
                $tickCount++;
                Log::debug("[BattleEffects] Debuff tick check: battle={$battleId} entity={$entityType} instance={$instanceId} field={$field} tickCount={$tickCount} interval={$interval} skillId={$skillId}");
                if ($tickCount >= max(1, $interval)) {
                    $casterId = $data['caster_id'] ?? null;
                    $casterType = $data['caster_type'] ?? null;

                    // resolvedCaster/Target: monta payload esperado por BattleActions
                    $resolvedCaster = ($casterId && $casterType)
                        ? $this->resolveEntityForTick($battleId, (string)$casterType, (string)$casterId)
                        : $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    $resolvedTarget = $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    // enriquece resolvedCaster/Target com 'name' (útil para logs e mensagens)
                    $this->enrichEntityWithName($battleId, $resolvedCaster);
                    $this->enrichEntityWithName($battleId, $resolvedTarget);

                    // monta target simples no formato esperado por BattleActions (um único alvo)
                    $targetRefKey = $entityType === 'monster' ? 'monsters' : 'characters_data';
                    $targets = [
                        'ref_key' => $targetRefKey,
                        'refInstanceId' => (string)$instanceId,
                        'category' => $entityType === 'monster' ? 'monster' : 'character'
                    ];

                    try {
                        // usa BattleActions
                        $targetId = $targets['refInstanceId'];
                        $targetKey = $targets['ref_key'];

                        $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                        $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                        Log::channel('battle_debug')->info("[processBattleEffects] Executing action", [
                            'battleId' => $battleId,
                            'casterInstanceId' => $instanceId,
                            'skillId' => $skillId,
                            'targetKey' => $targetKey,
                            'targetId' => $targetId,
                        ]);

                        $someoneDied = \App\Battle\BattleActions::executeAction($resolvedCaster, $skillId, $targetRef, $battleId, $resolvedCaster['type'], $targets['category']);

                        // atualiza estado local com a "fresh" entidade do Redis
                        $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                        if ($freshJson) {
                            $fresh = json_decode($freshJson, true);
                            if ($targetKey === 'monsters') {
                                $monsters[$targetId] = $fresh;
                            } else {
                                $players[$targetId] = $fresh;
                            }
                        } else {
                            Log::warning("[processBattleEffects] After applySkill, fresh entity missing in Redis", [
                                'battleId' => $battleId,
                                'targetKey' => $targetKey,
                                'targetId' => $targetId
                            ]);
                        }

                        if (!empty($someoneDied)) {
                            // sinaliza que precisamos checar fim de batalha depois do loop
                            $needCheckBattleEnd = true;
                        }

                        Log::info("[BattleEffects] Applied debuff tick skill {$skillId} for field {$field} on {$entityType} {$instanceId} (key {$debuffsKey})", [
                            'battle' => $battleId,
                            'entityType' => $entityType,
                            'instanceId' => $instanceId,
                            'field' => $field
                        ]);
                    } catch (\Throwable $e) {
                        Log::error("[BattleEffects] Failed debuff tick skill {$skillId} for {$field}: " . $e->getMessage(), [
                            'battle' => $battleId,
                            'entityType' => $entityType,
                            'instanceId' => $instanceId,
                            'field' => $field,
                            'debuff' => $data,
                        ]);
                    }

                    // reset tick counter after firing
                    $tickCount = 0;
                    $processed = true;
                }
            }

            $data['tick_count'] = $tickCount;

            // persist back to the same key we read from
            Redis::hset($debuffsKey, $field, json_encode($data, JSON_UNESCAPED_UNICODE));
            $processed = true;
        }

        // --- BUFFS: same approach (per-instance key preferred, fallback to shared monsters:buffs) ---
        $perInstanceBuffsKey = $baseKey . ":buffs";
        $sharedMonstersBuffsKey = "battle:$battleId:monsters:buffs";
        if ($isCharacter) {
            $buffsKey = $perInstanceBuffsKey;
            $useSharedBuffs = false;
        } else {
            if (Redis::exists($perInstanceBuffsKey)) {
                $buffsKey = $perInstanceBuffsKey;
                $useSharedBuffs = false;
            } else {
                $buffsKey = $sharedMonstersBuffsKey;
                $useSharedBuffs = true;
            }
        }

        $buffs = Redis::hgetall($buffsKey) ?: [];

        foreach ($buffs as $field => $json) {
            if ($useSharedBuffs) {
                if (strpos((string)$field, $instanceId . ':') !== 0) {
                    continue;
                }
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                Redis::hdel($buffsKey, $field);
                Log::warning("[BattleEffects] Buff JSON inválido for {$entityType} {$instanceId} field {$field} (key {$buffsKey})");
                $processed = true;
                continue;
            }

            if (isset($data['duration']) && $data['duration'] !== null) {
                $data['duration'] = (int)$data['duration'] - 1;
                if ($data['duration'] <= 0) {
                    Redis::hdel($buffsKey, $field);
                    Log::info("[BattleEffects] Removed expired buff {$field} from {$entityType} {$instanceId} (key {$buffsKey})");
                    $processed = true;
                    continue;
                }
            }

            $tickCount = (int)($data['tick_count'] ?? 0);
            $interval = isset($data['tick_interval']) ? (int)$data['tick_interval'] : 1;
            $skillId = isset($data['tick_skill_id']) ? (int)$data['tick_skill_id'] : null;

            if ($skillId) {
                $tickCount++;
                Log::debug("[BattleEffects] Buff tick check: battle={$battleId} entity={$entityType} instance={$instanceId} field={$field} tickCount={$tickCount} interval={$interval} skillId={$skillId}");
                if ($tickCount >= max(1, $interval)) {
                    $casterId = $data['caster_id'] ?? null;
                    $casterType = $data['caster_type'] ?? null;

                    $resolvedCaster = ($casterId && $casterType)
                        ? $this->resolveEntityForTick($battleId, (string)$casterType, (string)$casterId)
                        : $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    $resolvedTarget = $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    // adiciona nome quando possível
                    $this->enrichEntityWithName($battleId, $resolvedCaster);
                    $this->enrichEntityWithName($battleId, $resolvedTarget);

                    $targetRefKey = $entityType === 'monster' ? 'monsters' : 'characters_data';
                    $targets = [
                        'ref_key' => $targetRefKey,
                        'refInstanceId' => (string)$instanceId,
                        'category' => $entityType === 'monster' ? 'monster' : 'character'
                    ];

                    try {
                        // usa BattleActions
                        $targetId = $targets['refInstanceId'];
                        $targetKey = $targets['ref_key'];

                        $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                        $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                        Log::channel('battle_debug')->info("[processBattleEffects] Executing action", [
                            'battleId' => $battleId,
                            'casterInstanceId' => $instanceId,
                            'skillId' => $skillId,
                            'targetKey' => $targetKey,
                            'targetId' => $targetId,
                        ]);

                        \App\Battle\BattleActions::executeAction($resolvedCaster, $skillId, $targetRef, $battleId, $resolvedCaster['type'], $targets['category']);

                        // atualiza estado local com a "fresh" entidade do Redis
                        $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                        if ($freshJson) {
                            $fresh = json_decode($freshJson, true);
                            if ($targetKey === 'monsters') {
                                $monsters[$targetId] = $fresh;
                            } else {
                                $players[$targetId] = $fresh;
                            }
                        } else {
                            Log::warning("[processBattleEffects] After applySkill, fresh entity missing in Redis", [
                                'battleId' => $battleId,
                                'targetKey' => $targetKey,
                                'targetId' => $targetId
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::error("[BattleEffects] Failed buff tick skill {$skillId} for {$field}: " . $e->getMessage(), [
                            'battle' => $battleId,
                            'entityType' => $entityType,
                            'instanceId' => $instanceId,
                            'field' => $field,
                            'buff' => $data,
                        ]);
                    }

                    $tickCount = 0;
                    $processed = true;
                }
            }

            $data['tick_count'] = $tickCount;
            Redis::hset($buffsKey, $field, json_encode($data, JSON_UNESCAPED_UNICODE));
            $processed = true;
        }


        if ($needCheckBattleEnd) {
            $this->checkBattleEnd($battleId, $players, $monsters);
        }

        return $processed;
    }

    public function finishBattle(string $battleId): void
    {
        Redis::srem('battles:active', $battleId);

        // Busca todas as chaves que começam com "battle:<id>:"
        $pattern = "battle:$battleId:*";
        $cursor = '0';

        do {
            [$cursor, $keys] = Redis::scan($cursor, ['match' => $pattern, 'count' => 10]);

            if (!empty($keys)) {
                Redis::del($keys);
            }
        } while ($cursor !== '0');

        Log::info("Battle $battleId finalized and all Redis keys removed.");
    }


    public function cleanupOldBattles(int $maxAgeSeconds = 3600): void
    {
        $now = time();
        $battleIds = Redis::smembers('battles:active');

        foreach ($battleIds as $battleId) {
            $lastUpdate = Redis::get("battle:$battleId:last_update");

            if (!$lastUpdate || ($now - (int)$lastUpdate) > $maxAgeSeconds) {
                $this->finishBattle($battleId);
            }
        }
    }



    public function processBattleUsers(string $battleId): bool
    {
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data");
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");

        if (!$playersRaw) return false;

        $monsters = [];
        foreach ($monstersRaw as $k => $json) {
            $m = json_decode($json, true);
            if (!isset($m['instanceId'])) $m['instanceId'] = (string)$k;
            $monsters[$k] = $m;
        }

        $players = [];
        foreach ($playersRaw as $k => $json) {
            $p = json_decode($json, true);
            if (!isset($p['instanceId'])) $p['instanceId'] = (string)$k;
            $players[$k] = $p;
        }

        $processedAny = false;
        $needCheckBattleEnd = false;

        // PRIORIDADE: processa primeiro trocas de soul pendentes
        $soulChangesProcessed = $this->processPendingSoulChanges($battleId);
        if ($soulChangesProcessed) {
            $processedAny = true;
            // soul changes podem alterar skills -> marca last update já internamente
            // não precisamos checar fim de batalha aqui por conta própria (troca de soul não mata ninguém)
        }

        // Depois processa ações (skills/ataques) dos jogadores
        $result = $this->processPendingActions($battleId, $players, $monsters);
        if ($result['processed']) {
            $processedAny = true;
        }
        if ($result['needCheckBattleEnd']) {
            $needCheckBattleEnd = true;
        }

        // Checa fim de batalha uma vez, usando os arrays locais atualizados
        if ($needCheckBattleEnd) {
            $this->checkBattleEnd($battleId, $players, $monsters);
        }

        return $processedAny;
    }

    public function processBattleMonsters(string $battleId): bool
    {
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data");

        if (!$monstersRaw || !$playersRaw) {
            Log::warning("[processBattleMonsters] Sem monstros ou jogadores na batalha $battleId");
            return false;
        }

        $monsters = [];
        foreach ($monstersRaw as $k => $json) {
            $m = json_decode($json, true);
            if (!isset($m['instanceId'])) $m['instanceId'] = (string)$k;
            $monsters[$k] = $m;
        }

        $players = [];
        foreach ($playersRaw as $k => $json) {
            $p = json_decode($json, true);
            if (!isset($p['instanceId'])) $p['instanceId'] = (string)$k;
            $players[$k] = $p;
        }

        $processed = false;
        $needCheckBattleEnd = false; // NOVO: sinaliza se precisamos checar fim após o processamento


        foreach ($monsters as $monsterKey => &$monster) {
            $monsterCurrentStamina = StaminaService::getCurrentStamina($battleId, (string)$monsterKey, 'monster');
            $monster['current_stamina'] = $monsterCurrentStamina;

            Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) current stamina: $monsterCurrentStamina");

            // 1️⃣ Carrega as skills do Redis
            $skillsJson = Redis::get("battle:$battleId:monster:{$monsterKey}:skills");
            $monsterSkills = $skillsJson ? json_decode($skillsJson, true) : [];

            if (!$monsterSkills) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não possui skills carregadas");
            }

            $behavior = $this->resolveBehavior($monster['type'] ?? '');

            if (!$behavior) {
                Log::warning("[processBattleMonsters] Behavior não encontrado para tipo {$monster['type']}");
                continue;
            }

            // 2️⃣ Passa o array de skills como parte do contexto
            $action = $behavior->decideAction($monster, [
                'monsters' => $monsters,
                'players' => $players,
                'battle_id' => $battleId,
                'skills' => $monsterSkills, // <-- aqui
            ]);

            if (!$action) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não realizou nenhuma ação");
                continue;
            }

            // Checagem de stamina: se insuficiente, ignora ação
            $staminaCost = SkillService::getSkillStaminaCost(
                $action['skill_id'],
                $battleId,
                'monster',       // tipo do caster
                (string) $monsterKey,  // casterId (id do monstro no Redis)

            );
            $requiredStamina = $staminaCost ?? 0;
            if ($monsterCurrentStamina < $requiredStamina) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não tem stamina suficiente ({$monsterCurrentStamina} < {$requiredStamina}), ação descartada");
                continue;
            }

            if (!isset($action['caster_id'])) {
                $action['caster_id'] = (string)$monsterKey;
            }
            if (!isset($action['caster_type'])) {
                $action['caster_type'] = 'monster';
            }


            $targets = $this->resolveTargets($action, $monster, $players, $monsters, 'monster');

            // LOG: targets resolvidos
            Log::channel('battle_debug')->info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) targets resolvidos", [
                'action' => $action,
                'targets' => $targets
            ]);

            if (empty($targets)) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não encontrou targets para o action", ['action' => $action]);
                continue;
            }

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $battleActions = new \App\Battle\BattleActions();

                foreach ($targets as $t) {
                    $targetId = $t['refInstanceId'];
                    $targetKey = $t['ref_key'];

                    $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                    $someoneDied = $battleActions->executeAction($monster, $skillId, $targetRef, $battleId, 'monster', $t['category']);


                    $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    if ($freshJson) {
                        $fresh = json_decode($freshJson, true);
                        if ($targetKey === 'monsters') {
                            $monsters[$targetId] = $fresh;
                        } else {
                            $players[$targetId] = $fresh;
                        }
                    } else {
                        Log::warning("After monster applySkill, fresh entity missing in Redis", [
                            'battleId' => $battleId,
                            'targetKey' => $targetKey,
                            'targetId' => $targetId
                        ]);
                    }
                    if ($someoneDied) {
                        // não checar imediatamente, apenas sinalizar
                        $needCheckBattleEnd = true;
                    }
                }

                $processed = true;
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) executed action $skillId");
            } catch (\Throwable $e) {
                Log::error("[processBattleMonsters] Erro ao processar ação do monstro {$monsterKey}: " . $e->getMessage(), ['exception' => $e]);
            }
        }
        if ($needCheckBattleEnd) {
            $this->checkBattleEnd($battleId, $players, $monsters);
        }

        return $processed;
    }

    /**
     * Processa pending actions (ações de jogadores) para a batalha.
     *
     * Recebe $players e $monsters por referência para manter o estado local atualizado
     * após cada aplicação de skill (fresh entities lidas do Redis).
     *
     * Retorna array:
     *  - 'processed' => bool (se processou ao menos uma ação)
     *  - 'needCheckBattleEnd' => bool (se houve alguém morto e precisamos checar fim)
     */
    public function processPendingActions(string $battleId, array &$players, array &$monsters): array
    {
        $pendingActionsKey = "battle:$battleId:pending_actions";
        $pendingActions = Redis::hgetall($pendingActionsKey);

        $processed = false;
        $needCheckBattleEnd = false;

        if (empty($pendingActions)) {
            return ['processed' => false, 'needCheckBattleEnd' => false];
        }

        foreach ($pendingActions as $instanceId => $actionJson) {
            $instanceId = (string)$instanceId; // instanceId na batalha (não DB id)
            $action = json_decode($actionJson, true);

            // se o player não existe mais no contexto da batalha, remove a entry
            if (!isset($players[$instanceId])) {
                // cleanup: remove pending action keyed por instanceId
                Redis::hdel($pendingActionsKey, $instanceId);
                continue;
            }

            $caster = &$players[$instanceId];

            // LOG: ação recebida
            Log::channel('battle_debug')->info("[processPendingActions] Processing action", [
                'instanceId' => $instanceId,
                'caster' => $caster,
                'action' => $action
            ]);

            $targets = $this->resolveTargets($action, $caster, $players, $monsters, 'character');

            // LOG: targets resolvidos
            Log::channel('battle_debug')->info("[processPendingActions] Targets resolved", [
                'instanceId' => $instanceId,
                'targets' => $targets
            ]);

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $battleActions = new \App\Battle\BattleActions();

                foreach ($targets as $t) {
                    $targetId = $t['refInstanceId'];
                    $targetKey = $t['ref_key'];

                    $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                    Log::channel('battle_debug')->info("[processPendingActions] Executing action", [
                        'battleId' => $battleId,
                        'casterInstanceId' => $instanceId,
                        'skillId' => $skillId,
                        'targetKey' => $targetKey,
                        'targetId' => $targetId,
                    ]);

                    $someoneDied = $battleActions->executeAction($caster, $skillId, $targetRef, $battleId, 'character', $t['category']);

                    // atualiza estado local com a "fresh" entidade do Redis
                    $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    if ($freshJson) {
                        $fresh = json_decode($freshJson, true);
                        if ($targetKey === 'monsters') {
                            $monsters[$targetId] = $fresh;
                        } else {
                            $players[$targetId] = $fresh;
                        }
                    } else {
                        Log::warning("[processPendingActions] After applySkill, fresh entity missing in Redis", [
                            'battleId' => $battleId,
                            'targetKey' => $targetKey,
                            'targetId' => $targetId
                        ]);
                    }

                    if ($someoneDied) {
                        $needCheckBattleEnd = true;
                    }
                }

                $processed = true;
            } catch (\Exception $e) {
                Log::error("[processPendingActions] Error processing action for instance {$instanceId}: " . $e->getMessage(), ['exception' => $e]);
            }

            // marca que terminou a execução e remove pending cache — usa instanceId aqui
            Redis::del("battle:$battleId:skill_in_execution:{$instanceId}");
            Redis::hdel($pendingActionsKey, $instanceId);
        }

        return ['processed' => $processed, 'needCheckBattleEnd' => $needCheckBattleEnd];
    }




    public function checkBattleEnd(string $battleId, ?array $playersLocal = null, ?array $monstersLocal = null): void
    {
        // Lock Redis para evitar finalização concorrente (com retry curto)
        $lockKey = "battle:{$battleId}:finish_lock";
        $gotLock = false;
        $attempts = 10; // tenta por ~100ms (10 * 10ms)
        while ($attempts-- > 0) {
            if (Redis::setnx($lockKey, 1)) {
                // conseguiu
                Redis::expire($lockKey, 5); // expira em 5s como safety
                $gotLock = true;
                break;
            }
            // aguarda um pouco antes de tentar novamente (10ms)
            usleep(10000);
        }

        if (! $gotLock) {
            // Se não conseguiu lock após tentativas, loga e continua (finalização é idempotente)
            Log::warning("[checkBattleEnd] Não conseguiu adquirir lock para $battleId após tentativas; continuará sem lock.");
        }

        try {
            // Usar arrays locais se passados (eles já estão decodificados); senão ler do Redis
            if (is_array($playersLocal)) {
                $playersIter = array_values($playersLocal); // mantemos só os valores
            } else {
                $playersRaw = Redis::hgetall("battle:$battleId:characters_data");
                $playersIter = array_map(fn($p) => is_string($p) ? json_decode($p, true) : $p, $playersRaw);
            }

            if (is_array($monstersLocal)) {
                $monstersIter = array_values($monstersLocal);
            } else {
                $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
                $monstersIter = array_map(fn($m) => is_string($m) ? json_decode($m, true) : $m, $monstersRaw);
            }

            $allPlayersDead = true;
            foreach ($playersIter as $player) {
                $stats = $player['stats'] ?? null;
                $stats = is_string($stats) ? json_decode($stats, true) : $stats;
                if (($stats['current_hp'] ?? 0) > 0) {
                    $allPlayersDead = false;
                    break;
                }
            }

            $allMonstersDead = true;
            foreach ($monstersIter as $monster) {
                $stats = $monster['stats'] ?? null;
                $stats = is_string($stats) ? json_decode($stats, true) : $stats;
                if (($stats['current_hp'] ?? 0) > 0) {
                    $allMonstersDead = false;
                    break;
                }
            }

            if ($allPlayersDead) {
                Log::info("[BattleManager] Todos os jogadores morreram na batalha $battleId. Finalizando...");
                $this->finishBattle($battleId);
                return;
            }

            if ($allMonstersDead) {
                Log::info("[BattleManager] Todos os monstros morreram na batalha $battleId. Jogadores venceram!");
                $this->finishBattle($battleId);
                return;
            }
        } finally {
            // só remove o lock se nós o adquirimos
            if ($gotLock) {
                Redis::del($lockKey);
            }
        }
    }

    /**
     * Processa mudanças de soul enfileiradas para a batalha.
     * (mantive o método que você já tinha — sem alterações aqui)
     */
    public function processPendingSoulChanges(string $battleId): bool
    {
        $pendingKey = "battle:$battleId:pending_soul_changes";
        $entries = Redis::hgetall($pendingKey);

        if (!$entries) {
            return false;
        }

        $processedAny = false;

        foreach ($entries as $instanceId => $actionJson) {
            $instanceId = (string)$instanceId; // instanceId
            $action = json_decode($actionJson, true);

            if (!is_array($action)) {
                Log::warning("[processPendingSoulChanges] ação inválida ou JSON malformado", [
                    'battle' => $battleId,
                    'instanceId' => $instanceId,
                    'raw' => $actionJson,
                ]);
                Redis::hdel($pendingKey, $instanceId);
                Redis::del("battle:$battleId:soul_change_in_execution:{$instanceId}");
                continue;
            }

            $slotIndex = isset($action['slot_index']) ? (int)$action['slot_index'] : null;
            if ($slotIndex === null) {
                Log::warning("[processPendingSoulChanges] slot_index ausente na ação", [
                    'battle' => $battleId,
                    'instanceId' => $instanceId,
                    'action' => $action,
                ]);
                Redis::hdel($pendingKey, $instanceId);
                Redis::del("battle:$battleId:soul_change_in_execution:{$instanceId}");
                continue;
            }

            // Recupera grid equipado do Redis (pré-carregado no início da batalha) — usa instanceId
            $gridKey = "battle:$battleId:character:{$instanceId}:equipped_soul_grid";
            $gridRaw = Redis::get($gridKey);

            if (!$gridRaw) {
                Log::warning("[processPendingSoulChanges] equipped_soul_grid não encontrado no Redis", [
                    'battle' => $battleId,
                    'instanceId' => $instanceId,
                ]);
                Redis::hdel($pendingKey, $instanceId);
                Redis::del("battle:$battleId:soul_change_in_execution:{$instanceId}");
                continue;
            }

            $soulsArray = json_decode($gridRaw, true);

            if (!is_array($soulsArray) || !isset($soulsArray[$slotIndex])) {
                Log::warning("[processPendingSoulChanges] slot inválido para equipped_soul_grid", [
                    'battle' => $battleId,
                    'instanceId' => $instanceId,
                    'slot_index' => $slotIndex,
                ]);
                Redis::hdel($pendingKey, $instanceId);
                Redis::del("battle:$battleId:soul_change_in_execution:{$instanceId}");
                continue;
            }

            $activeSoul = $soulsArray[$slotIndex];

            // Segurança: garante que `skills` esteja em array
            $activeSkills = $activeSoul['skills'] ?? [];
            if (!is_array($activeSkills)) $activeSkills = [];

            // Faz update atômico (multi/exec)
            try {
                Redis::multi();

                Redis::set("battle:$battleId:character:{$instanceId}:active_soul_id", $activeSoul['id'] ?? null);
                Redis::set(
                    "battle:$battleId:character:{$instanceId}:skills",
                    json_encode($activeSkills, JSON_UNESCAPED_UNICODE)
                );

                Redis::hdel($pendingKey, $instanceId);
                Redis::del("battle:$battleId:soul_change_in_execution:{$instanceId}");

                Redis::exec();

                $this->updateLastUpdate($battleId);

                Log::info("[processPendingSoulChanges] Soul change aplicado", [
                    'battle' => $battleId,
                    'instanceId' => $instanceId,
                    'slot_index' => $slotIndex,
                    'new_active_soul_id' => $activeSoul['id'] ?? null,
                    'skills_count' => count($activeSkills),
                ]);

                // Broadcast usando instanceId nas keys do players
                try {
                    $characterName = Redis::get("battle:$battleId:character:{$instanceId}:name") ?? "Jogador {$instanceId}";
                    $soulName = $activeSoul['name'] ?? 'Soul desconhecida';

                    $playersRaw = Redis::hgetall("battle:$battleId:characters_data");
                    $playersPayload = [];

                    foreach ($playersRaw as $pInstanceId => $pJson) {
                        $pData = is_string($pJson) ? json_decode($pJson, true) : (array)$pJson;
                        if (!is_array($pData)) $pData = [];

                        // garante instanceId
                        $pData['instanceId'] = (string)($pData['instanceId'] ?? $pInstanceId);

                        // tenta extrair current_hp de forma segura (stats pode vir string ou array)
                        $stats = $pData['stats'] ?? null;
                        if (is_string($stats)) {
                            $stats = json_decode($stats, true) ?: null;
                        }
                        $currentHp = isset($stats['current_hp']) ? (int)$stats['current_hp'] : 0;

                        $playersPayload[] = [
                            'instanceId'    => (string) $pData['instanceId'],
                            'soulSlotIndex' => isset($pData['soulSlotIndex']) ? (int)$pData['soulSlotIndex'] : -1,

                        ];
                    }

                    $updatePayload = [
                        'players' => $playersPayload,
                        'enemies' => [], // ou montar monsters de forma similar se quiser
                        'general' => [
                            'actionInfoUse'    => "$characterName trocou de Soul",
                            'actionInfoResult' => "Nova Soul ativa: $soulName",
                            'globalMessages'   => ["$characterName agora está usando $soulName"],
                        ],
                    ];

                    // loga o JSON para verificar formato antes do broadcast
                    Log::debug('[processPendingSoulChanges] updatePayload JSON: ' . json_encode($updatePayload, JSON_UNESCAPED_UNICODE));

                    BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
                } catch (\Throwable $e) {
                    Log::error("[processPendingSoulChanges] Erro ao enviar broadcast da troca de soul", [
                        'battle' => $battleId,
                        'instanceId' => $instanceId,
                        'error' => $e->getMessage(),
                    ]);
                }

                $processedAny = true;
            } catch (\Throwable $e) {
                try {
                    Redis::discard();
                } catch (\Throwable $_) {
                }
                Log::error("[processPendingSoulChanges] erro aplicando soul change", [
                    'battle' => $battleId,
                    'instanceId' => $instanceId,
                    'error' => $e->getMessage(),
                    'action' => $action,
                ]);
                Redis::hdel($pendingKey, $instanceId);
                Redis::del("battle:$battleId:soul_change_in_execution:{$instanceId}");
            }
        }

        return $processedAny;
    }
}
