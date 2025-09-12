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

    // processBattlePendingSkills (batch + pipeline)
    public function processBattlePendingSkills(string $battleId): bool
    {
        $zsetKey = "battle:{$battleId}:pending_skills_zset";
        $hashKey = "battle:{$battleId}:pending_skills_data";

        $now = microtime(true);
        $processed = false;
        $someoneDiedAny = false;

        $batchSize = 100;
        while (true) {
            $eventIds = Redis::zrangebyscore($zsetKey, '-inf', $now, 'LIMIT', 0, $batchSize);
            if (empty($eventIds)) break;

            // busca os JSONs em pipeline
            $responses = Redis::pipeline(function ($pipe) use ($hashKey, $eventIds) {
                foreach ($eventIds as $id) $pipe->hget($hashKey, $id);
            });

            // acumula operações
            $writes = [];
            $delHash = [];
            $remZ = [];
            $delProcessing = [];

            foreach ($eventIds as $i => $eventId) {
                $json = $responses[$i] ?? null;
                if (!$json) {
                    $remZ[] = $eventId;
                    continue;
                }

                $event = json_decode($json, true);
                if (!$event || !isset($event['ready_at'])) {
                    $delHash[] = $eventId;
                    $remZ[] = $eventId;
                    continue;
                }

                $casterId = (string)($event['caster_id'] ?? '');
                $casterType = ($event['caster_type'] ?? 'character');
                $casterLockKey = "caster_lock:{$battleId}:{$casterType}:{$casterId}";
                $casterLockUntil = (float)(Redis::get($casterLockKey) ?? 0.0);

                if ($casterLockUntil > $now) {
                    $event['ready_at'] = $casterLockUntil;
                    $writes[] = ['hset', $hashKey, $eventId, json_encode($event)];
                    $writes[] = ['zadd', $zsetKey, $event['ready_at'], $eventId];
                    continue;
                }

                $targetId = isset($event['target_id']) ? (string)$event['target_id'] : null;
                $targetType = ($event['target_type'] ?? 'character');
                $targetLockKey = $targetId ? "skill_lock:{$battleId}:{$targetType}:{$targetId}" : null;
                $targetLockUntil = $targetLockKey ? (float)(Redis::get($targetLockKey) ?? 0.0) : 0.0;

                $preDelaySec = ($event['pre_delay'] ?? 0) / 1000;
                $animationSec = ($event['animation_time'] ?? 0) / 1000;
                $postDelaySec = ($event['post_delay'] ?? 0) / 1000;
                $skillLockMs   = ($event['lock_time'] ?? 0);

                $newReadyAt = $now + $preDelaySec;
                if ($targetLockUntil > $newReadyAt) $newReadyAt = $targetLockUntil;

                $processingKey = "skill_processing:{$battleId}:{$casterType}:{$casterId}:{$eventId}";
                $alreadyProcessing = Redis::set($processingKey, 1, 'NX', 'EX', 10);
                if (!$alreadyProcessing) continue;

                $baseLockDuration = $preDelaySec + $animationSec + $postDelaySec;
                if ($skillLockMs > 0) $baseLockDuration += ceil($skillLockMs / 1000.0);
                if ($targetLockUntil > $now) $baseLockDuration += ($targetLockUntil - $now);
                $finalLock = $now + $baseLockDuration;
                $expire = (int) ceil($finalLock - $now);
                if ($expire > 0) $writes[] = ['set', $processingKey . ':caster_lock', $finalLock, 'EX', $expire];

                if (($event['phase'] ?? '') === 'pre_delay') {
                    $event['phase'] = 'animation';
                    $event['ready_at'] = $newReadyAt + $animationSec;
                    $writes[] = ['hset', $hashKey, $eventId, json_encode($event)];
                    $writes[] = ['zadd', $zsetKey, $event['ready_at'], $eventId];
                    $this->notifyBattle($battleId, 'animation', $event);
                    $processed = true;
                } elseif (($event['phase'] ?? '') === 'animation') {
                    $someoneDied = false;
                    if (!empty($event['skill_id'])) $someoneDied = $this->finalizeSkillCast($battleId, $event);
                    elseif (!empty($event['item_id'])) $someoneDied = $this->finalizeItemCast($battleId, $event);

                    if ($someoneDied) $someoneDiedAny = true;
                    $delHash[] = $eventId;
                    $remZ[] = $eventId;
                    $delProcessing[] = $processingKey;
                    $processed = true;
                }
            }

            // aplica tudo em pipeline
            if (!empty($writes) || !empty($delHash) || !empty($remZ) || !empty($delProcessing)) {
                /** @var string $hashKey */
                /** @var string $zsetKey */
                Redis::pipeline(function ($pipe) use ($hashKey, $zsetKey, $writes, $delHash, $remZ, $delProcessing) {
                    foreach ($writes as $op) {
                        if ($op[0] === 'hset') $pipe->hset($op[1], $op[2], $op[3]);
                        elseif ($op[0] === 'zadd') $pipe->zadd($op[1], [$op[3] => $op[2]]);
                        elseif ($op[0] === 'set') $pipe->set($op[1], $op[2], $op[3], $op[4] ?? null);
                    }
                    foreach ($delHash as $id) $pipe->hdel($hashKey, $id);
                    foreach ($remZ as $id) $pipe->zrem($zsetKey, $id);
                    foreach ($delProcessing as $k) $pipe->del($k);
                });
            }

            if (count($eventIds) < $batchSize) break;
        }

        if ($someoneDiedAny) $this->rebuildAndCheckBattle($battleId);
        return $processed;
    }


    // Trecho otimizado do BattleManager — métodos: processBattleEffects e processEffectsForEntity
    // Objetivo: reduzir roundtrips Redis com pipelines, cache local e writes agrupados.

    public function processBattleEffects(string $battleId): bool
    {
        $processedAny = false;

        // 1) Carrega todos os instanceIds dos characters em batch
        $charInstanceIds = Redis::smembers("battle:$battleId:characters_instances") ?: [];

        // Pipeline para obter as entidades dos characters por instanceId (hget no hash characters_data)
        $charactersRaw = [];
        if (!empty($charInstanceIds)) {
            $responses = Redis::pipeline(function ($pipe) use ($battleId, $charInstanceIds) {
                foreach ($charInstanceIds as $instanceId) {
                    $pipe->hget("battle:$battleId:characters_data", $instanceId);
                }
            });

            foreach ($charInstanceIds as $i => $instanceId) {
                $charactersRaw[$instanceId] = $responses[$i] ?? null;
            }
        }

        // Processa cada character (usando cache local e writes em pipeline por entidade)
        foreach ($charInstanceIds as $instanceId) {
            $charJson = $charactersRaw[$instanceId] ?? null;
            $charEntity = $charJson ? json_decode($charJson, true) : null;
            $charStats = $charEntity['stats'] ?? [];
            if (is_string($charStats)) $charStats = json_decode($charStats, true) ?: [];

            $processed = $this->processEffectsForEntityOptimized(
                $battleId,
                'character',
                (string)$instanceId,
                $charStats
            );

            if ($processed) $processedAny = true;
        }

        // ------------- PROCESSA MONSTERS (carrega HASH inteiro uma vez) -------------
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters") ?: [];
        foreach ($monstersRaw as $monsterInstanceId => $monsterJson) {
            $monsterEntity = $monsterJson ? json_decode($monsterJson, true) : null;
            $monsterStats = $monsterEntity['stats'] ?? [];
            if (is_string($monsterStats)) $monsterStats = json_decode($monsterStats, true) ?: [];

            $processed = $this->processEffectsForEntityOptimized(
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
     * Versão otimizada do processEffectsForEntity.
     * - Usa HGETALL apenas uma vez por key
     * - Agrupa HSET/HDEL em pipelines ao final do processamento da entidade
     * - Reduz hget repetidos para targets dentro de loops de stacks
     */
    private function processEffectsForEntityOptimized(
        string $battleId,
        string $entityType,
        string $instanceId,
        array $stats
    ): bool {
        $processed = false;
        $isCharacter = $entityType === 'character';

        $entityPrefix = $isCharacter ? 'characters_data' : 'monsters';
        $baseKey = "battle:$battleId:{$entityPrefix}:{$instanceId}";

        $debuffsKey = $baseKey . ':debuffs';
        $buffsKey   = $baseKey . ':buffs';

        // 1) Lê tudo de debuffs e buffs (uma chamada cada)
        $debuffsAll = Redis::hgetall($debuffsKey) ?: [];
        $buffsAll = Redis::hgetall($buffsKey) ?: [];

        // Coleções para escrever em batch
        $debuffsToSet = [];
        $debuffsToDel = [];

        $buffsToSet = [];
        $buffsToDel = [];

        // Helper para resolver target reference (retorna array ou null)
        $resolveTargetRef = function (string $targetKey, string $targetId) use ($battleId) {
            $json = Redis::hget("battle:$battleId:$targetKey", $targetId);
            return $json ? json_decode($json, true) : null;
        };

        // ------------------ Processa Debuffs ------------------
        foreach ($debuffsAll as $field => $json) {
            $data = json_decode($json, true);
            if (!is_array($data)) {
                $debuffsToDel[] = $field;
                $processed = true;
                continue;
            }

            // decrement duration
            if (isset($data['duration']) && $data['duration'] !== null) {
                $data['duration'] = (int)$data['duration'] - 1;
                if ($data['duration'] <= 0) {
                    // handler pode fazer limpeza de stacks corretamente (usa Redis internamente)
                    $this->handleEffectRemoval($battleId, $entityType, $instanceId, 'debuff', $field);
                    $processed = true;
                    // já removido pelo handler, não re-add
                    continue;
                }
            }

            $tickCount = (int)($data['tick_count'] ?? 0);
            $interval = isset($data['tick_interval']) ? (int)$data['tick_interval'] : 1;
            $skillId = isset($data['tick_skill_id']) ? (int)$data['tick_skill_id'] : null;

            if ($skillId) {
                $tickCount++;
                if ($tickCount >= max(1, $interval)) {
                    // Resolve caster/target apenas quando necessário
                    $casterId = $data['caster_id'] ?? null;
                    $casterType = $data['caster_type'] ?? null;

                    $resolvedCaster = ($casterId && $casterType)
                        ? $this->resolveEntityForTick($battleId, (string)$casterType, (string)$casterId)
                        : $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    $resolvedTarget = $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    $this->enrichEntityWithName($battleId, $resolvedCaster);
                    $this->enrichEntityWithName($battleId, $resolvedTarget);

                    $targetRefKey = $entityType === 'monster' ? 'monsters' : 'characters_data';
                    $targetId = (string)$instanceId;

                    // Lê target apenas UMA vez antes dos stacks
                    $targetRef = $resolveTargetRef($targetRefKey, $targetId);

                    try {
                        $maxStacks = 10;
                        $stacks = min(max(1, (int)($data['stacks'] ?? 1)), $maxStacks);

                        for ($i = 0; $i < $stacks; $i++) {
                            // Executa ação. Passamos $targetRef (snapshot) para reduzir hgets repetidos.
                            $someoneDied = \App\Battle\BattleActions::executeAction(
                                $resolvedCaster,
                                $skillId,
                                null,
                                $targetRef,
                                $battleId,
                                $resolvedCaster['type'],
                                $entityType === 'monster' ? 'monster' : 'character'
                            );

                            // Após aplicar, precisamos atualizar o snapshot do target uma vez por conjunto (no final)
                            // para evitar hgets por iteração. Portanto, não hget aqui a cada iteração.
                        }

                        // Recupera fresh entity UMA vez para refletir atualizações feitas pelas stacks
                        $freshJson = Redis::hget("battle:$battleId:$targetRefKey", $targetId);
                        if ($freshJson) {
                            $fresh = json_decode($freshJson, true);
                            // se precisar propagar para algum cache local do caller, o caller deve gerenciar isso
                        } else {
                            // target possivelmente removido
                            Log::channel('battle_debug')->warning("[processEffectsForEntityOptimized] After tick applySkill, fresh entity missing", [
                                'battleId' => $battleId,
                                'targetKey' => $targetRefKey,
                                'targetId' => $targetId,
                            ]);
                        }

                        Log::info("[BattleEffects] Applied debuff tick skill {$skillId} for {$field} on {$entityType} {$instanceId}; stacks={$stacks}");
                    } catch (\Throwable $e) {
                        Log::error("[BattleEffects] Failed debuff tick skill {$skillId} for {$field}: " . $e->getMessage(), [
                            'battle' => $battleId,
                            'entityType' => $entityType,
                            'instanceId' => $instanceId,
                            'field' => $field,
                            'debuff' => $data,
                        ]);
                    }

                    $tickCount = 0;
                    $processed = true;
                }
            }

            $data['tick_count'] = $tickCount;
            $debuffsToSet[$field] = json_encode($data, JSON_UNESCAPED_UNICODE);
            $processed = true;
        }

        // ------------------ Processa Buffs ------------------
        foreach ($buffsAll as $field => $json) {
            $data = json_decode($json, true);
            if (!is_array($data)) {
                $buffsToDel[] = $field;
                $processed = true;
                continue;
            }

            if (isset($data['duration']) && $data['duration'] !== null) {
                $data['duration'] = (int)$data['duration'] - 1;
                if ($data['duration'] <= 0) {
                    $this->handleEffectRemoval($battleId, $entityType, $instanceId, 'buff', $field);
                    $processed = true;
                    continue;
                }
            }

            $tickCount = (int)($data['tick_count'] ?? 0);
            $interval = isset($data['tick_interval']) ? (int)$data['tick_interval'] : 1;
            $skillId = isset($data['tick_skill_id']) ? (int)$data['tick_skill_id'] : null;

            if ($skillId) {
                $tickCount++;
                if ($tickCount >= max(1, $interval)) {
                    $casterId = $data['caster_id'] ?? null;
                    $casterType = $data['caster_type'] ?? null;

                    $resolvedCaster = ($casterId && $casterType)
                        ? $this->resolveEntityForTick($battleId, (string)$casterType, (string)$casterId)
                        : $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    $resolvedTarget = $this->resolveEntityForTick($battleId, $entityType, $instanceId);

                    $this->enrichEntityWithName($battleId, $resolvedCaster);
                    $this->enrichEntityWithName($battleId, $resolvedTarget);

                    $targetRefKey = $entityType === 'monster' ? 'monsters' : 'characters_data';
                    $targetId = (string)$instanceId;

                    // snapshot do target
                    $targetRef = $resolveTargetRef($targetRefKey, $targetId);

                    try {
                        $stacks = max(1, (int)($data['stacks'] ?? 1));
                        for ($i = 0; $i < $stacks; $i++) {
                            \App\Battle\BattleActions::executeAction(
                                $resolvedCaster,
                                $skillId,
                                null,
                                $targetRef,
                                $battleId,
                                $resolvedCaster['type'],
                                $entityType === 'monster' ? 'monster' : 'character'
                            );
                        }

                        // atualiza snapshot uma vez
                        $freshJson = Redis::hget("battle:$battleId:$targetRefKey", $targetId);
                        if (!$freshJson) {
                            Log::warning("[processEffectsForEntityOptimized] After applySkill, fresh entity missing", [
                                'battleId' => $battleId,
                                'targetKey' => $targetRefKey,
                                'targetId' => $targetId
                            ]);
                        }

                        Log::info("[BattleEffects] Applied buff tick skill {$skillId} for {$field} on {$entityType} {$instanceId}; stacks={$stacks}");
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
            $buffsToSet[$field] = json_encode($data, JSON_UNESCAPED_UNICODE);
            $processed = true;
        }

        // ------------- Executa pipelines de escrita agrupada ----------------
        Redis::pipeline(function ($pipe) use (
            $debuffsKey,
            $debuffsToSet,
            $debuffsToDel,
            $buffsKey,
            $buffsToSet,
            $buffsToDel
        ) {
            // Debuffs: set
            foreach ($debuffsToSet as $field => $val) {
                $pipe->hset($debuffsKey, $field, $val);
            }
            // Debuffs: del
            foreach ($debuffsToDel as $field) {
                $pipe->hdel($debuffsKey, $field);
            }

            // Buffs: set
            foreach ($buffsToSet as $field => $val) {
                $pipe->hset($buffsKey, $field, $val);
            }
            // Buffs: del
            foreach ($buffsToDel as $field) {
                $pipe->hdel($buffsKey, $field);
            }
        });

        return $processed;
    }


    // cleanupOldBattles (pipeline get last_update)
    public function cleanupOldBattles(int $maxAgeSeconds = 3600): void
    {
        $now = time();
        $battleIds = Redis::smembers('battles:active') ?: [];
        if (empty($battleIds)) return;

        $lastUpdates = Redis::pipeline(function ($pipe) use ($battleIds) {
            foreach ($battleIds as $id) $pipe->get("battle:$id:last_update");
        });

        foreach ($battleIds as $i => $battleId) {
            $lastUpdate = $lastUpdates[$i] ?? null;
            if (!$lastUpdate || ($now - (int)$lastUpdate) > $maxAgeSeconds) $this->finishBattle($battleId);
        }
    }


    // processBattleStateSync (broadcast + cleanup inválidos)
    public function processBattleStateSync(string $battleId): void
    {
        $ackKey = "battle:$battleId:acks";
        $pending = Redis::hgetall($ackKey) ?: [];
        if (empty($pending)) return;

        foreach ($pending as $ackId => $json) {
            $payload = json_decode($json, true);
            if (!$payload) {
                Redis::hdel($ackKey, $ackId);
                continue;
            }
            BattleBroadcaster::broadcastToBattle($battleId, $payload, 'battle-state');
            Log::info("[BattleAcks] Broadcast ack event for {$ackId}");
            // manter no Redis até receber ACK do cliente
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

        return $processedAny;
    }

    // processBattleMonsters (preload players/monsters + pending map)
    public function processBattleMonsters(string $battleId): bool
    {
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters") ?: [];
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data") ?: [];
        if (empty($monstersRaw) || empty($playersRaw)) return false;

        $players = [];
        foreach ($playersRaw as $k => $json) {
            $p = $json ? json_decode($json, true) : [];
            $p['instanceId'] = (string)($p['instanceId'] ?? $k);
            $players[$k] = $p;
        }
        $monsters = [];
        foreach ($monstersRaw as $k => $json) {
            $m = $json ? json_decode($json, true) : [];
            $m['instanceId'] = (string)($m['instanceId'] ?? $k);
            $monsters[$k] = $m;
        }

        // preload pending events -> map id => json
        $pendingEventIds = Redis::zrangebyscore("battle:{$battleId}:pending_skills_zset", '-inf', '+inf');
        $pendingMap = [];
        if (!empty($pendingEventIds)) {
            $pendingJsons = Redis::pipeline(function ($pipe) use ($battleId, $pendingEventIds) {
                foreach ($pendingEventIds as $id) $pipe->hget("battle:$battleId:pending_skills_data", $id);
            });
            foreach ($pendingEventIds as $i => $id) $pendingMap[$id] = $pendingJsons[$i] ?? null;
        }

        $processed = false;
        foreach ($monsters as $monsterKey => &$monster) {
            if (!empty($monster['isCasting'])) continue;
            $monsterCurrentStamina = StaminaService::getCurrentStamina($battleId, (string)$monsterKey, 'monster');
            $monster['current_stamina'] = $monsterCurrentStamina;

            $skillsJson = Redis::get("battle:$battleId:monster:{$monsterKey}:skills");
            $monsterSkills = $skillsJson ? json_decode($skillsJson, true) : [];
            $behavior = $this->resolveBehavior($monster['type'] ?? '');
            if (!$behavior) continue;

            // checa pending no map (evita hget repetido)
            $hasPendingSkill = false;
            foreach ($pendingMap as $ej) {
                if (!$ej) continue;
                $ev = json_decode($ej, true);
                if (($ev['caster_id'] ?? null) === (string)$monsterKey) {
                    $hasPendingSkill = true;
                    break;
                }
            }
            if ($hasPendingSkill) continue;

            $action = $behavior->decideAction($monster, ['monsters' => $monsters, 'players' => $players, 'battle_id' => $battleId, 'skills' => $monsterSkills]);
            if (!$action) continue;

            $staminaCost = SkillService::getSkillStaminaCost($action['skill_id'], $battleId, 'monster', (string)$monsterKey) ?? 0;
            if ($monsterCurrentStamina < $staminaCost) continue;

            if (!isset($action['caster_id'])) $action['caster_id'] = (string)$monsterKey;
            if (!isset($action['caster_type'])) $action['caster_type'] = 'monster';

            $targets = $this->resolveTargets($action, $monster, $players, $monsters, 'monster');
            if (empty($targets)) continue;

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $battleActions = new \App\Battle\BattleActions();

                // marca isCasting local + write único (evita vários hsets)
                $monster['isCasting'] = true;
                Redis::hset("battle:$battleId:monsters", $monsterKey, json_encode($monster));

                foreach ($targets as $t) {
                    $targetJson = Redis::hget("battle:$battleId:{$t['ref_key']}", $t['refInstanceId']);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;
                    $monsterJson = Redis::hget("battle:$battleId:monsters", $monsterKey);
                    $monster = $monsterJson ? json_decode($monsterJson, true) : $monster;

                    $battleActions->executeAction($monster, $skillId, null, $targetRef, $battleId, 'monster', $t['category']);

                    $freshJson = Redis::hget("battle:$battleId:{$t['ref_key']}", $t['refInstanceId']);
                    if ($freshJson) {
                        $fresh = json_decode($freshJson, true);
                        if ($t['ref_key'] === 'monsters') $monsters[$t['refInstanceId']] = $fresh;
                        else $players[$t['refInstanceId']] = $fresh;
                    }
                }

                $processed = true;
            } catch (\Throwable $e) {
                Log::error("[processBattleMonsters] " . $e->getMessage());
            }
        }

        return $processed;
    }

    // processPendingActions (batch delete + pipeline)
    public function processPendingActions(string $battleId, array &$players, array &$monsters): array
    {
        $pendingActionsKey = "battle:$battleId:pending_actions";
        $pendingActions = Redis::hgetall($pendingActionsKey) ?: [];

        $processed = false;
        $needCheckBattleEnd = false;
        if (empty($pendingActions)) return ['processed' => false, 'needCheckBattleEnd' => false];

        $toDelete = [];

        foreach ($pendingActions as $instanceId => $actionJson) {
            $instanceId = (string)$instanceId;
            $action = json_decode($actionJson, true);

            if (!isset($players[$instanceId])) {
                $toDelete[] = $instanceId;
                continue;
            }

            $processingKey = "processing_action:{$battleId}:{$instanceId}";
            $got = Redis::set($processingKey, 1, 'NX', 'EX', 5);
            if (!$got) continue;

            $caster = &$players[$instanceId];
            $targets = $this->resolveTargets($action, $caster, $players, $monsters, 'character');

            try {
                $skillId = isset($action['skill_id']) ? (int)$action['skill_id'] : null;
                $itemId  = isset($action['item_id']) ? (int)$action['item_id'] : null;
                if ($skillId === null && $itemId === null) {
                    $toDelete[] = $instanceId;
                    continue;
                }

                $battleActions = new \App\Battle\BattleActions();

                foreach ($targets as $t) {
                    $targetJson = Redis::hget("battle:$battleId:{$t['ref_key']}", $t['refInstanceId']);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                    $battleActions->executeAction($caster, $skillId, $itemId, $targetRef, $battleId, 'character', $t['category']);
                    $toDelete[] = $instanceId;

                    $freshJson = Redis::hget("battle:$battleId:{$t['ref_key']}", $t['refInstanceId']);
                    if ($freshJson) {
                        $fresh = json_decode($freshJson, true);
                        if ($t['ref_key'] === 'monsters') $monsters[$t['refInstanceId']] = $fresh;
                        else $players[$t['refInstanceId']] = $fresh;
                    }
                }

                $processed = true;
            } catch (\Throwable $e) {
                Log::error("[processPendingActions] " . $e->getMessage());
            } finally {
                Redis::del($processingKey);
            }
        }

        if (!empty($toDelete)) {
            $toDelete = array_values(array_unique($toDelete));
            Redis::pipeline(function ($pipe) use ($pendingActionsKey, $toDelete) {
                foreach ($toDelete as $id) $pipe->hdel($pendingActionsKey, $id);
            });
        }

        return ['processed' => $processed, 'needCheckBattleEnd' => $needCheckBattleEnd];
    }







    // processPendingSoulChanges (preload grids + multi/exec)
    public function processPendingSoulChanges(string $battleId): bool
    {
        $pendingKey = "battle:$battleId:pending_soul_changes";
        $entries = Redis::hgetall($pendingKey) ?: [];
        if (empty($entries)) return false;

        $gridKeys = [];
        foreach ($entries as $instanceId => $_) $gridKeys[$instanceId] = "battle:$battleId:character:{$instanceId}:equipped_soul_grid";
        $gridValues = Redis::pipeline(function ($pipe) use ($gridKeys) {
            foreach ($gridKeys as $k) $pipe->get($k);
        });

        $i = 0;
        $processedAny = false;
        $toDelete = [];

        foreach ($entries as $instanceId => $actionJson) {
            $action = json_decode($actionJson, true);
            if (!is_array($action)) {
                $toDelete[] = $instanceId;
                $i++;
                continue;
            }

            $slotIndex = isset($action['slot_index']) ? (int)$action['slot_index'] : null;
            if ($slotIndex === null) {
                $toDelete[] = $instanceId;
                $i++;
                continue;
            }

            $gridRaw = $gridValues[$i++] ?? null;
            if (!$gridRaw) {
                $toDelete[] = $instanceId;
                continue;
            }

            $soulsArray = json_decode($gridRaw, true);
            if (!is_array($soulsArray) || !isset($soulsArray[$slotIndex])) {
                $toDelete[] = $instanceId;
                continue;
            }

            $activeSoul = $soulsArray[$slotIndex];
            $activeSkills = $activeSoul['skills'] ?? [];
            if (!is_array($activeSkills)) $activeSkills = [];

            try {
                Redis::multi();
                Redis::set("battle:$battleId:character:{$instanceId}:active_soul_id", $activeSoul['id'] ?? null);
                Redis::set("battle:$battleId:character:{$instanceId}:skills", json_encode($activeSkills, JSON_UNESCAPED_UNICODE));
                Redis::hdel($pendingKey, $instanceId);
                Redis::del("battle:$battleId:soul_change_in_execution:{$instanceId}");
                Redis::exec();

                $this->updateLastUpdate($battleId);
                $processedAny = true;
            } catch (\Throwable $e) {
                try {
                    Redis::discard();
                } catch (\Throwable $_) {
                }
                Log::error("[processPendingSoulChanges] " . $e->getMessage());
                $toDelete[] = $instanceId;
            }
        }

        if (!empty($toDelete)) {
            $toDelete = array_values(array_unique($toDelete));
            Redis::pipeline(function ($pipe) use ($pendingKey, $toDelete) {
                foreach ($toDelete as $id) $pipe->hdel($pendingKey, $id);
            });
        }

        return $processedAny;
    }
}
