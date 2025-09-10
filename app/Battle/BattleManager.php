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

    public function processBattlePendingSkills(string $battleId): bool
    {
        $zsetKey = "battle:{$battleId}:pending_skills_zset";
        $hashKey = "battle:{$battleId}:pending_skills_data";

        $now = microtime(true);
        $processed = false;
        $someoneDiedAny = false;

        // Pega todos eventos com ready_at <= agora
        $readyEvents = Redis::zrangebyscore($zsetKey, '-inf', $now);

        foreach ($readyEvents as $eventId) {
            $json = Redis::hget($hashKey, $eventId);
            if (!$json) {
                Redis::zrem($zsetKey, $eventId); // limpeza de segurança
                continue;
            }

            $event = json_decode($json, true);
            if (!$event || !isset($event['ready_at'])) continue;

            $casterId = (string)($event['caster_id'] ?? '');
            $casterType = ($event['caster_type'] ?? 'character'); // importante
            $casterLockKey = "caster_lock:{$battleId}:{$casterType}:{$casterId}";
            $casterLockUntil = (float)(Redis::get($casterLockKey) ?? 0.0);

            // Se o caster estiver ocupado, postergar a skill
            if ($casterLockUntil > $now) {
                $event['ready_at'] = $casterLockUntil;
                Redis::hset($hashKey, $eventId, json_encode($event));
                Redis::zadd($zsetKey, [$eventId => $event['ready_at']]);
                continue;
            }

            // Lock do target: postergar se necessário e/ou para usar no cálculo do bloqueio final
            $targetId = isset($event['target_id']) ? (string)$event['target_id'] : null;
            $targetType = ($event['target_type'] ?? 'character'); // importante
            $targetLockKey = $targetId ? "skill_lock:{$battleId}:{$targetType}:{$targetId}" : null;
            $targetLockUntil = $targetLockKey ? (float)(Redis::get($targetLockKey) ?? 0.0) : 0.0;

            // Calcula total ready_at considerando pre_delay, animation_time, post_delay (em segundos)
            $preDelaySec = ($event['pre_delay'] ?? 0) / 1000;
            $animationSec = ($event['animation_time'] ?? 0) / 1000;
            $postDelaySec = ($event['post_delay'] ?? 0) / 1000;
            $skillLockMs   = ($event['lock_time'] ?? 0); // ms

            // nova ready (considerando preDelay e lock do target já existente)
            $newReadyAt = $now + $preDelaySec;
            if ($targetLockUntil > $newReadyAt) {
                $newReadyAt = $targetLockUntil;
            }

            // Lock de processamento: impede duplicação de execução da mesma skill
            // incluir casterType também (caso queira evitar colisões de eventId)
            $processingKey = "skill_processing:{$battleId}:{$casterType}:{$casterId}:{$eventId}";
            $alreadyProcessing = Redis::set($processingKey, 1, 'NX', 'EX', 10);
            if (!$alreadyProcessing) {
                Log::channel('battle_debug')->debug("Skill {$eventId} já está em execução, pulando...");
                continue;
            }

            // === aplica o lock do caster IMEDIATAMENTE quando aceitamos/processamos a entrada ===
            // Calcula duração do bloqueio do caster: pre + animation + post
            $baseLockDuration = $preDelaySec + $animationSec + $postDelaySec; // em segundos

            // Se o evento tiver lock_time explícito (ms) adicione (convertendo)
            if ($skillLockMs > 0) {
                $baseLockDuration += ceil($skillLockMs / 1000.0);
            }

            // Se o target já tem um lock no Redis (salvo pelo Lua), some a diferença positiva
            if ($targetLockUntil > $now) {
                $extra = $targetLockUntil - $now;
                $baseLockDuration += $extra;
            }

            // Define o finalLock relativo ao agora
            $finalLock = $now + $baseLockDuration;

            // Só setar se realmente houver duração positiva
            $expire = (int) ceil($finalLock - $now);
            if ($expire > 0) {
                // grava o timestamp final no key e define EX para limpeza automática
                Redis::set($casterLockKey, $finalLock, 'EX', $expire);
            }

            // Fase pre_delay -> animation
            if ($event['phase'] === 'pre_delay') {
                $event['phase'] = 'animation';
                $event['ready_at'] = $newReadyAt + $animationSec;
                Redis::hset($hashKey, $eventId, json_encode($event));
                Redis::zadd($zsetKey, [$eventId => $event['ready_at']]);
                $this->notifyBattle($battleId, 'animation', $event);
                $processed = true;
            }
            // Fase animation -> finalize
            elseif ($event['phase'] === 'animation') {
                $someoneDied = false;

                if (isset($event['skill_id']) && $event['skill_id']) {
                    // É skill
                    $someoneDied = $this->finalizeSkillCast($battleId, $event);
                } elseif (isset($event['item_id']) && $event['item_id']) {
                    // É item
                    $someoneDied = $this->finalizeItemCast($battleId, $event);
                } else {
                    Log::channel('battle_debug')->warning("[processBattlePendingSkills] Evento sem skill_id ou item_id", [
                        'battle' => $battleId,
                        'event' => $event
                    ]);
                }

                if ($someoneDied) $someoneDiedAny = true;

                // Remove evento da fila
                Redis::hdel($hashKey, $eventId);
                Redis::zrem($zsetKey, $eventId);

                // Limpeza do processingKey
                Redis::del($processingKey);

                $processed = true;
            }
        }

        if ($someoneDiedAny) $this->rebuildAndCheckBattle($battleId);

        return $processed;
    }








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


    private function processEffectsForEntity(
        string $battleId,
        string $entityType,
        string $instanceId,
        array $stats
    ): bool {
        $processed = false;
        $isCharacter = $entityType === 'character';
        $entityPrefix = $isCharacter ? "characters_data" : "monsters";

        // BASE per-instance key (ex: battle:<id>:characters_data:<instanceId>)
        $baseKey = "battle:$battleId:{$entityPrefix}:{$instanceId}";

        // --- PER-INSTANCE keys (no fallback) ---
        $debuffsKey = $baseKey . ":debuffs";
        $buffsKey   = $baseKey . ":buffs";

        $debuffs = Redis::hgetall($debuffsKey) ?: [];

        foreach ($debuffs as $field => $json) {
            $data = json_decode($json, true);
            if (!is_array($data)) {
                Redis::hdel($debuffsKey, $field);
                Log::warning("[BattleEffects] Debuff JSON inválido for {$entityType} {$instanceId} field {$field} (key {$debuffsKey})");
                $processed = true;
                continue;
            }

            if (isset($data['duration']) && $data['duration'] !== null) {
                $data['duration'] = (int)$data['duration'] - 1;
                if ($data['duration'] <= 0) {
                    // usa o handler para decrementar/remover corretamente considerando stacks
                    $this->handleEffectRemoval($battleId, $entityType, $instanceId, "debuff", $field);
                    $processed = true;
                    continue;
                }
            }

            $tickCount = (int)($data['tick_count'] ?? 0);
            $interval = isset($data['tick_interval']) ? (int)$data['tick_interval'] : 1;
            $skillId = isset($data['tick_skill_id']) ? (int)$data['tick_skill_id'] : null;

            if ($skillId) {
                $tickCount++;
                Log::debug("[BattleEffects] Debuff tick check: battle={$battleId} entity={$entityType} instance={$instanceId} field={$field} tickCount={$tickCount} interval={$interval} skillId={$skillId}");
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
                    $targets = [
                        'ref_key' => $targetRefKey,
                        'refInstanceId' => (string)$instanceId,
                        'category' => $entityType === 'monster' ? 'monster' : 'character'
                    ];

                    try {
                        $maxStacks = 10;
                        $stacks = min(max(1, (int)($data['stacks'] ?? 1)), $maxStacks);
                        $someoneDiedAny = false;

                        for ($i = 0; $i < $stacks; $i++) {
                            $targetId = $targets['refInstanceId'];
                            $targetKey = $targets['ref_key'];
                            $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                            $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                            Log::channel('battle_debug')->info("[processBattleEffects] Executing tick iteration", [
                                'battleId' => $battleId,
                                'caster' => $resolvedCaster,
                                'skillId' => $skillId,
                                'iteration' => $i + 1,
                                'stacks' => $stacks,
                                'targetKey' => $targetKey,
                                'targetId' => $targetId,
                            ]);

                            $someoneDied = \App\Battle\BattleActions::executeAction(
                                $resolvedCaster,
                                $skillId,
                                null,
                                $targetRef,
                                $battleId,
                                $resolvedCaster['type'],
                                $targets['category']
                            );

                            $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                            if ($freshJson) {
                                $fresh = json_decode($freshJson, true);
                                if ($targetKey === 'monsters') {
                                    $monsters[$targetId] = $fresh;
                                } else {
                                    $players[$targetId] = $fresh;
                                }
                            } else {
                                Log::channel('battle_debug')->warning("[processBattleEffects] After tick applySkill, fresh entity missing in Redis", [
                                    'battleId' => $battleId,
                                    'targetKey' => $targetKey,
                                    'targetId' => $targetId
                                ]);
                            }
                        }

                        Log::info("[BattleEffects] Applied debuff tick skill {$skillId} for {$field} on {$entityType} {$instanceId} (key {$debuffsKey}); stacks={$stacks}", [
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

                    $tickCount = 0;
                    $processed = true;
                }
            }

            $data['tick_count'] = $tickCount;
            Redis::hset($debuffsKey, $field, json_encode($data, JSON_UNESCAPED_UNICODE));
            $processed = true;
        }

        // --- BUFFS (per-instance only) ---
        $buffs = Redis::hgetall($buffsKey) ?: [];

        foreach ($buffs as $field => $json) {
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
                    $this->handleEffectRemoval($battleId, $entityType, $instanceId, "buff", $field);
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

                    $this->enrichEntityWithName($battleId, $resolvedCaster);
                    $this->enrichEntityWithName($battleId, $resolvedTarget);

                    $targetRefKey = $entityType === 'monster' ? 'monsters' : 'characters_data';
                    $targets = [
                        'ref_key' => $targetRefKey,
                        'refInstanceId' => (string)$instanceId,
                        'category' => $entityType === 'monster' ? 'monster' : 'character'
                    ];

                    try {
                        $stacks = max(1, (int)($data['stacks'] ?? 1));
                        $someoneDiedAny = false;

                        for ($i = 0; $i < $stacks; $i++) {
                            $targetId = $targets['refInstanceId'];
                            $targetKey = $targets['ref_key'];
                            $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                            $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                            Log::channel('battle_debug')->info("[processBattleEffects] Executing buff tick iteration", [
                                'battleId' => $battleId,
                                'caster' => $resolvedCaster,
                                'skillId' => $skillId,
                                'iteration' => $i + 1,
                                'stacks' => $stacks,
                                'targetKey' => $targetKey,
                                'targetId' => $targetId,
                            ]);

                            \App\Battle\BattleActions::executeAction(
                                $resolvedCaster,
                                $skillId,
                                null,
                                $targetRef,
                                $battleId,
                                $resolvedCaster['type'],
                                $targets['category']
                            );

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
                        }

                        Log::info("[BattleEffects] Applied buff tick skill {$skillId} for {$field} on {$entityType} {$instanceId} (key {$buffsKey}); stacks={$stacks}");
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

        return $processed;
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

    public function processBattleStateSync(string $battleId): void
    {
        $ackKey = "battle:$battleId:acks";
        $pending = Redis::hgetall($ackKey) ?: [];

        foreach ($pending as $ackId => $json) {
            $payload = json_decode($json, true);
            if (!$payload) {
                Redis::hdel($ackKey, $ackId);
                continue;
            }

            // 🔥 Aqui você faz o broadcast para o cliente
            BattleBroadcaster::broadcastToBattle($battleId, $payload, 'battle-state');

            Log::info("[BattleAcks] Broadcast ack event for {$ackId}", ['payload' => $payload]);

            // 👇 Mantém no Redis até receber o ACK do cliente
            // O listener de ACK (quando o cliente responder) deve dar:
            // Redis::hdel($ackKey, $ackId);
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


        foreach ($monsters as $monsterKey => &$monster) {

            // ✅ Checagem se o monstro já está executando uma skill
            if (!empty($monster['isCasting'])) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) já está executando uma skill, pulando");
                continue;
            }
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

            // ✅ Checagem se já existe skill pendente no ZSET
            $pendingEvents = Redis::zrangebyscore("battle:{$battleId}:pending_skills_zset", '-inf', '+inf');
            $hasPendingSkill = false;

            foreach ($pendingEvents as $eventId) {
                $eventJson = Redis::hget("battle:{$battleId}:pending_skills_data", $eventId);
                if (!$eventJson) continue;
                $event = json_decode($eventJson, true);
                if (($event['caster_id'] ?? null) === (string)$monsterKey) {
                    $hasPendingSkill = true;
                    break;
                }
            }

            if ($hasPendingSkill) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) já tem skill pendente, pulando");
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


                    $monster['isCasting'] = true; // impede múltiplas execuções simultâneas
                    Redis::hset("battle:$battleId:monsters", $monsterKey, json_encode($monster));
                    $battleActions->executeAction($monster, $skillId, null, $targetRef, $battleId, 'monster', $t['category']);


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
                }

                $processed = true;
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) executed action $skillId");
            } catch (\Throwable $e) {
                Log::error("[processBattleMonsters] Erro ao processar ação do monstro {$monsterKey}: " . $e->getMessage(), ['exception' => $e]);
            }
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

            // 1) lock por instance para evitar dois workers processando a mesma entry
            $processingKey = "processing_action:{$battleId}:{$instanceId}";
            $got = Redis::set($processingKey, 1, 'NX', 'EX', 5); // TTL curto — ajuste ao seu tick
            if (!$got) {
                Log::channel('battle_debug')->debug("[processPendingActions] Instance {$instanceId} já em processamento, pulando.");
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
                $skillId = isset($action['skill_id']) ? (int)$action['skill_id'] : null;
                $itemId  = isset($action['item_id']) ? (int)$action['item_id'] : null;

                if ($skillId === null && $itemId === null) {
                    Log::warning("[processPendingActions] Nenhum skill_id ou item_id para instance $instanceId");
                    Redis::hdel($pendingActionsKey, $instanceId);
                    continue;
                }
                $battleActions = new \App\Battle\BattleActions();

                foreach ($targets as $t) {
                    $targetId = $t['refInstanceId'];
                    $targetKey = $t['ref_key'];

                    $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                    Log::channel('battle_debug')->info("[processPendingActions] Executing action", [
                        'battleId' => $battleId,
                        'casterInstanceId' => $instanceId,
                        'skillId' => $skillId ?? "null",
                        'itemId' => $itemId ?? "null",
                        'targetKey' => $targetKey,
                        'targetId' => $targetId,
                    ]);

                    $battleActions->executeAction(
                        $caster,
                        $skillId,
                        $itemId,
                        $targetRef,
                        $battleId,
                        'character',
                        $t['category']
                    );
                    // <-- AQUI: removemos a pending_action IMEDIATAMENTE, pois já movemos a ação para pending_skills
                    Redis::hdel($pendingActionsKey, $instanceId);
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
                }

                $processed = true;
            } catch (\Exception $e) {
                Log::error("[processPendingActions] Error processing action for instance {$instanceId}: " . $e->getMessage(), ['exception' => $e]);
            }
        }

        return ['processed' => $processed, 'needCheckBattleEnd' => $needCheckBattleEnd];
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
                            'soulSlotIndex' => $slotIndex,

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
