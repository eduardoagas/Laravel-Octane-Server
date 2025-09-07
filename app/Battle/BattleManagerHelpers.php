<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\BattleBroadcaster;

class BattleManagerHelpers

{

    /**
     * Remove ou decrementa stacks de um efeito (buff/debuff) de uma entidade
     *
     * @param string $battleId
     * @param string $entityType "character" ou "monster"
     * @param string $instanceId
     * @param string $effectType "buff" ou "debuff"
     * @param string $field Nome da key do efeito (ex: targetId:stat:casterId)
     * @param int $stacksToRemove Quantos stacks remover (default 1)
     * @param bool $forceDelete Ignora stacks e remove direto (ex: skill Remedy)
     */
    protected function handleEffectRemoval(
        string $battleId,
        string $entityType,
        string $instanceId,
        string $effectType,
        string $field,
        int $stacksToRemove = 1,
        bool $forceDelete = false
    ): void {
        $entityPrefix = $entityType === 'character' ? 'characters_data' : 'monsters';
        $hashKey = "battle:$battleId:{$entityPrefix}:{$instanceId}:" . ($effectType === 'buff' ? 'buffs' : 'debuffs');
        $indexKey = "battle:$battleId:{$entityPrefix}:{$instanceId}:" . ($effectType === 'buff' ? 'buff_index' : 'debuff_index');
        $ackKey   = "battle:$battleId:acks";

        $json = Redis::hget($hashKey, $field);
        if (!$json) return;

        $data = json_decode($json, true);
        if (!is_array($data)) {
            Redis::hdel($hashKey, $field);
            Redis::srem($indexKey, $field);
            return;
        }

        if ($forceDelete) {
            $data['stacks'] = 0;
        } else {
            $data['stacks'] = max(0, ($data['stacks'] ?? 1) - $stacksToRemove);
        }

        if ($data['stacks'] <= 0) {
            Redis::hdel($hashKey, $field);
            Redis::srem($indexKey, $field);
            // Registrar ACK
            $ackId = "{$effectType}_remove:{$entityType}:{$instanceId}:{$field}";
            $payload = [
                'ackId' => $ackId,
                'stacks' => $data['stacks'] ?? 0,
                'note' => null,

                //'type' => "{$effectType}_remove",
                //'timestamp' => time(),
            ];
            Redis::hset($ackKey, $ackId, json_encode($payload, JSON_UNESCAPED_UNICODE));

            Log::info("[BattleAcks] Registered ACK {$ackId}", ['payload' => $payload]);
        } else {
            // Mantém o efeito com stacks atualizados
            $data['duration'] = $data['duration'] ?? null; // opcional: resetar duração ou manter
            Redis::hset($hashKey, $field, json_encode($data, JSON_UNESCAPED_UNICODE));
        }


        Log::info("[BattleEffects] Removed/updated {$effectType} {$field} from {$entityType} {$instanceId}");
    }

    protected function notifyBattle(string $battleId, string $stage, array $context = []): void
    {
        $globalMessages = [];
        $actionInfoUse = '';
        $actionInfoResult = '';

        // Pega nome da skill se houver
        $skillName = null;
        if (!empty($context['skill_id'])) {
            $skillService = new \App\Services\Battle\SkillService();
            $skillName = $skillService->getSkillName(
                (int)$context['skill_id'],
                $battleId,
                $context['caster_type'] ?? null,
                $context['caster_id'] ?? null
            );
        }

        switch ($stage) {
            case 'pre_delay':
                $actionInfoUse = "{$context['caster_type']} {$context['caster_id']} começou a conjurar skill {$skillName}";
                $globalMessages[] = "Skill {$skillName} em preparação";
                break;

            case 'animation':
                $actionInfoUse = "{$context['caster_type']} {$context['caster_id']} está animando skill {$skillName}";
                $globalMessages[] = "Skill {$skillName} entrou na fase de animação";
                break;

            case 'result':
                $actionInfoUse = $context['actionInfoUse'] ?? '';
                $actionInfoResult = $context['actionInfoResult'] ?? '';
                $globalMessages = $context['globalMessages'] ?? [];
                break;

            case 'error':
                $globalMessages = $context['errors'] ?? ["Erro inesperado"];
                break;
        }

        Log::info("Context de NotifyBattle = " . json_encode($context));

        $updatePayload = [
            'players' => $context['players'] ?? [],
            'enemies' => $context['enemies'] ?? [],
            'general' => [
                'actionInfoUse' => $actionInfoUse,
                'actionInfoResult' => $actionInfoResult,
                'globalMessages' => $globalMessages,
            ],
        ];

        BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');

        Log::channel('battle_debug')->info("[notifyBattle] Broadcast stage={$stage}", [
            'battleId' => $battleId,
            'context' => $context,
        ]);
    }


    protected function rebuildAndCheckBattle(string $battleId)
    {
        $monsters = [];
        foreach (Redis::hgetall("battle:$battleId:monsters") ?: [] as $k => $json) {
            $m = $json ? json_decode($json, true) : null;
            if (!is_array($m)) continue;
            $m['instanceId'] = $m['instanceId'] ?? (string)$k;
            $monsters[$k] = $m;
        }

        $players = [];
        foreach (Redis::hgetall("battle:$battleId:characters_data") ?: [] as $k => $json) {
            $p = $json ? json_decode($json, true) : null;
            if (!is_array($p)) continue;
            $p['instanceId'] = $p['instanceId'] ?? (string)$k;
            $players[$k] = $p;
        }

        Log::info("[BattleEffects] someoneDied detected, checking battle end", ['battle' => $battleId]);
        $this->checkBattleEnd($battleId, $players, $monsters);
    }

    protected function finalizeSkillCast(string $battleId, array $event): bool
    {
        $skillService = new \App\Services\Battle\SkillService();
        $globalMessages = [];
        $allResults = [];
        $someoneDied = false;

        $casterInstanceId = (string)($event['caster_id'] ?? '');
        $casterType = $event['caster_type'] ?? 'character';
        $skillId = $event['skill_id'] ?? null;
        $targetType = $event['target_type'] ?? 'character';

        if ($casterInstanceId === '' || !$skillId || (empty($event['target_id']) && empty($event['targets']))) {
            Log::channel('battle_debug')->warning("[finalizeSkillCast] Evento inválido", ['battle' => $battleId, 'event' => $event]);
            return false;
        }

        // Pega o nome da skill
        $skillName = $skillService->getSkillName(
            (int)$skillId,
            $battleId,
            $casterType,
            $casterInstanceId
        );

        $loadEntity = function (string $battleId, string $type, string $instanceId): ?array {
            $key = ($type === 'monster') ? "battle:{$battleId}:monsters" : "battle:{$battleId}:characters_data";
            $raw = Redis::hget($key, $instanceId);
            if (!$raw) return null;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            return is_array($decoded) ? $decoded : null;
        };

        $caster = $loadEntity($battleId, $casterType, $casterInstanceId);
        if (!$caster) {
            Log::channel('battle_debug')->warning("[finalizeSkillCast] Caster não encontrado", [
                'battle' => $battleId,
                'caster_type' => $casterType,
                'caster_instance' => $casterInstanceId,
                'event' => $event
            ]);
            return false;
        }

        $targetsList = [];
        if (!empty($event['target_id'])) {
            $targetsList[] = (string)$event['target_id'];
        } elseif (!empty($event['targets']) && is_array($event['targets'])) {
            foreach ($event['targets'] as $t) {
                if (is_string($t) || is_int($t)) $targetsList[] = (string)$t;
                elseif (is_array($t) && isset($t['instanceId'])) $targetsList[] = (string)$t['instanceId'];
            }
        }

        if (empty($targetsList)) {
            Log::channel('battle_debug')->warning("[finalizeSkillCast] Targets vazios ou inválidos", [
                'battle' => $battleId,
                'event' => $event
            ]);
            return false;
        }

        $staminaUpdates = [];
        $casterCurrentStamina = null;

        foreach ($targetsList as $targetInstanceId) {
            $target = $loadEntity($battleId, $targetType, $targetInstanceId);
            if (!$target) {
                $globalMessages[] = "Alvo {$targetInstanceId} não encontrado (pode ter sido removido).";
                Log::channel('battle_debug')->warning("[finalizeSkillCast] Target não encontrado, pulando", [
                    'battle' => $battleId,
                    'target_type' => $targetType,
                    'target_instance' => $targetInstanceId,
                    'event' => $event
                ]);
                continue;
            }

            if (!isset($target['stats'])) $target['stats'] = [];
            if (!isset($caster['stats'])) $caster['stats'] = [];

            try {
                $result = $skillService->applySkill($caster, $target, $battleId, (int)$skillId, $casterType, $targetType);
                Log::info("APPLY SKILL RESULT" . json_encode($result));
            } catch (\App\Exceptions\InsufficientStaminaException $e) {
                Log::channel('battle_debug')->warning("[finalizeSkillCast] Stamina insuficiente", [
                    'battle' => $battleId,
                    'caster' => $casterInstanceId,
                    'skill' => $skillId
                ]);
                return false;
            } catch (\Throwable $e) {
                Log::error("[finalizeSkillCast] Erro ao aplicar skill", [
                    'battle' => $battleId,
                    'exception' => $e,
                    'event' => $event
                ]);
                return false;
            }

            if (array_key_exists('current_stamina', $result) && $result['current_stamina'] !== null) {
                $casterCurrentStamina = $result['current_stamina'];
            }
            // Mantém o registro de used_stamina_total tanto do caster quanto do target
            foreach (['caster', 'target'] as $role) {
                $resId = (string)($result["{$role}_id"] ?? '');
                if ($resId !== '') {
                    $resType = $role === 'caster' ? $casterType : $targetType;
                    $staminaKey = "{$resType}:{$resId}"; // chave combinada tipo:id
                    $staminaUpdates[$staminaKey] = [
                        'used_stamina_total' => (float)($result['used_stamina_total'] ?? 0),
                        'current_stamina' => $result['current_stamina'] ?? null,
                    ];

                    Log::channel('battle_debug')->info("[finalizeSkillCast][StaminaUpdates] {$role}", [
                        'battle' => $battleId,
                        'staminaKey' => $staminaKey,
                        'staminaUpdate' => $staminaUpdates[$staminaKey]
                    ]);
                }
            }

            $casterName = $caster['name'] ?? 'Desconhecido';
            $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
            $targetTypeStr = ($targetType === 'monster') ? 'Monstro' : 'Jogador';
            $actionInfoUse = "{$casterName} usou skill {$skillName}";
            $actionInfoResult = '';

            if (isset($result['damage_dealt'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de {$result['damage_dealt']}";
            } elseif (isset($result['healed_amount'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de {$result['healed_amount']}";
            } elseif (isset($result['buff_applied'])) {
                $buff = $result['buff_applied'];
                $buffValue = $buff['bonus'] ?? ($buff['power'] ?? 0);
                $buffDuration = $buff['duration'] ?? '∞';
                $buffStat = $buff['stat'] ?? 'unknown';
                $actionInfoResult = "Buff aplicado: +{$buffValue} {$buffStat} por {$buffDuration} turnos";
            } elseif (isset($result['debuff_applied'])) {
                $debuff = $result['debuff_applied'];
                $debuffPower = $debuff['power'] ?? 0;
                $debuffDuration = $debuff['duration'] ?? '∞';
                $debuffStat = $debuff['stat'] ?? 'unknown';
                $actionInfoResult = "Debuff aplicado em {$targetName}: -{$debuffStat} ({$debuffPower}) por {$debuffDuration} turnos";
                $globalMessages[] = "⚡ Debuff de {$debuffStat} aplicado com sucesso em {$targetName}!";
            } elseif (!empty($result['debuff_failed'])) {
                $chance = $result['debuff_chance'] ?? null;
                $roll = $result['debuff_roll'] ?? null;
                $actionInfoResult = "Debuff falhou em {$targetName}";
                $globalMessages[] = "❌ Debuff em {$targetName} falhou (chance: " . round(($chance ?? 0) * 100, 1) . "%, roll: {$roll})";
            }

            if (!empty($result['someoneDied']) || !empty($result['target_died'])) {
                $globalMessages[] = "{$targetName} morreu!";
                $someoneDied = true;
            }

            $allResults[] = [
                'actionInfoUse' => $actionInfoUse,
                'actionInfoResult' => $actionInfoResult
            ];

            Log::channel('battle_debug')->info("[finalizeSkillCast] Aplicado result para target", [
                'battle' => $battleId,
                'caster' => $casterInstanceId,
                'target' => $targetInstanceId,
                'result' => $result
            ]);
        }

        // --- Players payload + stamina ---
        $playersPayload = [];
        foreach (Redis::hgetall("battle:$battleId:characters_data") as $playerId => $playerJson) {
            $playerData = is_string($playerJson) ? json_decode($playerJson, true) ?? [] : $playerJson;
            $playerStats = $playerData['stats'] ?? [];
            if (is_string($playerStats)) $playerStats = json_decode($playerStats, true) ?: [];

            $playerPayload = [
                'instanceId' => (string)$playerId,
                'currentHp' => (int)($playerStats['current_hp'] ?? 0),
            ];

            $playerIdKey = (string)$playerId;
            $playerStaminaKey = "character:{$playerIdKey}";
            if (isset($staminaUpdates[$playerStaminaKey])) {
                $stRaw = Redis::hget("battle:$battleId:stamina_data", $playerStaminaKey);
                $stParsed = is_string($stRaw) ? json_decode($stRaw, true) ?? [] : $stRaw;

                $playerPayload['staminaData'] = [
                    'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                    'start_time' => (int)($stParsed['start_time'] ?? 0),
                    'used_stamina_total' => (float)$staminaUpdates[$playerStaminaKey]['used_stamina_total'],
                    'current_stamina' => $staminaUpdates[$playerStaminaKey]['current_stamina'] ?? null,
                ];
            }

            $playersPayload[] = $playerPayload;
        }

        // --- Monsters payload + stamina ---
        $enemiesPayload = [];
        foreach (Redis::hgetall("battle:$battleId:monsters") as $monsterId => $monsterJson) {
            $monsterData = is_string($monsterJson) ? json_decode($monsterJson, true) ?? [] : $monsterJson;
            $monsterStats = $monsterData['stats'] ?? [];
            if (is_string($monsterStats)) $monsterStats = json_decode($monsterStats, true) ?: [];

            $monsterPayload = [
                'instanceId' => (string)$monsterId,
                'isAlive' => ($monsterStats['current_hp'] ?? 0) > 0,
            ];

            $monsterIdKey = (string)$monsterId;
            $monsterStaminaKey = "monster:{$monsterIdKey}";
            if (isset($staminaUpdates[$monsterStaminaKey])) {
                $stRaw = Redis::hget("battle:$battleId:stamina_data", $monsterStaminaKey);
                $stParsed = is_string($stRaw) ? json_decode($stRaw, true) ?? [] : $stRaw;

                $monsterPayload['staminaData'] = [
                    'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                    'start_time' => (int)($stParsed['start_time'] ?? 0),
                    'used_stamina_total' => (float)$staminaUpdates[$monsterStaminaKey]['used_stamina_total'],
                    'current_stamina' => $staminaUpdates[$monsterStaminaKey]['current_stamina'] ?? null,
                ];
            }

            $enemiesPayload[] = $monsterPayload;
        }

        $this->notifyBattle($battleId, 'result', [
            'players' => $playersPayload,
            'enemies' => $enemiesPayload,
            'actionInfoUse' => implode(' | ', array_column($allResults, 'actionInfoUse')),
            'actionInfoResult' => implode(' | ', array_column($allResults, 'actionInfoResult')),
            'globalMessages' => $globalMessages,
        ]);

        if ($casterCurrentStamina !== null) {
            Log::channel('battle_debug')->info("[finalizeSkillCast] Stamina atual do caster", [
                'instanceId' => $caster['instanceId'] ?? '',
                'current_stamina' => $casterCurrentStamina
            ]);
        }

        if ($casterType === 'character') {
            Redis::del("battle:$battleId:skill_in_execution:{$casterInstanceId}");
            Redis::hdel("battle:$battleId:pending_actions", $casterInstanceId);
        } elseif ($casterType === 'monster') {
            $caster['isCasting'] = false;
            Redis::hset("battle:$battleId:monsters", $casterInstanceId, json_encode($caster));
        }

        return $someoneDied;
    }

    protected function finalizeItemCast(string $battleId, array $event): bool
    {
        $itemService = new \App\Services\Battle\ItemService();
        $globalMessages = [];
        $allResults = [];
        $someoneDied = false;

        $casterInstanceId = (string)($event['caster_id'] ?? '');
        $casterType = $event['caster_type'] ?? 'character';
        $itemId = $event['item_id'] ?? null;
        $targetType = $event['target_type'] ?? 'character';

        if ($casterInstanceId === '' || !$itemId || (empty($event['target_id']) && empty($event['targets']))) {
            Log::channel('battle_debug')->warning("[finalizeItemCast] Evento inválido", ['battle' => $battleId, 'event' => $event]);
            return false;
        }

        $loadEntity = function (string $battleId, string $type, string $instanceId): ?array {
            $key = ($type === 'monster') ? "battle:{$battleId}:monsters" : "battle:{$battleId}:characters_data";
            $raw = Redis::hget($key, $instanceId);
            if (!$raw) return null;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            return is_array($decoded) ? $decoded : null;
        };

        $caster = $loadEntity($battleId, $casterType, $casterInstanceId);
        if (!$caster) {
            Log::channel('battle_debug')->warning("[finalizeItemCast] Caster não encontrado", [
                'battle' => $battleId,
                'caster_type' => $casterType,
                'caster_instance' => $casterInstanceId,
                'event' => $event
            ]);
            return false;
        }

        $targetsList = [];
        if (!empty($event['target_id'])) {
            $targetsList[] = (string)$event['target_id'];
        } elseif (!empty($event['targets']) && is_array($event['targets'])) {
            foreach ($event['targets'] as $t) {
                if (is_string($t) || is_int($t)) $targetsList[] = (string)$t;
                elseif (is_array($t) && isset($t['instanceId'])) $targetsList[] = (string)$t['instanceId'];
            }
        }

        if (empty($targetsList)) {
            Log::channel('battle_debug')->warning("[finalizeItemCast] Targets vazios ou inválidos", [
                'battle' => $battleId,
                'event' => $event
            ]);
            return false;
        }

        $staminaUpdates = [];
        $casterCurrentStamina = null;

        foreach ($targetsList as $targetInstanceId) {
            $target = $loadEntity($battleId, $targetType, $targetInstanceId);
            if (!$target) {
                $globalMessages[] = "Alvo {$targetInstanceId} não encontrado (pode ter sido removido).";
                continue;
            }

            // --- Log antes de aplicar o item ---
            Log::channel('battle_debug')->info("[finalizeItemCast][BeforeApplyItem] Target stamina antes do item", [
                'battle' => $battleId,
                'target_id' => $targetInstanceId,
                'stamina_data' => Redis::hget("battle:$battleId:stamina_data", "{$targetType}:$targetInstanceId")
            ]);

            try {
                $result = $itemService->applyConsumable($caster, $target, $battleId, (int)$itemId, $casterType, $targetType);
            } catch (\Throwable $e) {
                Log::error("[finalizeItemCast] Erro ao aplicar item", [
                    'battle' => $battleId,
                    'exception' => $e,
                    'event' => $event
                ]);
                continue;
            }

            // --- Log após aplicar o item ---
            Log::channel('battle_debug')->info("[finalizeItemCast][AfterApplyItem] Result do item", [
                'battle' => $battleId,
                'target_id' => $targetInstanceId,
                'result' => $result
            ]);

            if (array_key_exists('current_stamina', $result) && $result['current_stamina'] !== null) {
                $casterCurrentStamina = $result['current_stamina'];
            }
            // --- Registro de used_stamina_total tanto do caster quanto do target ---
            foreach (['caster', 'target'] as $role) {
                $resId = (string)($result["{$role}_id"] ?? '');
                if ($resId !== '') {
                    $resType = $role === 'caster' ? $casterType : $targetType;
                    $staminaKey = "{$resType}:{$resId}"; // <-- nova chave combinada
                    $staminaUpdates[$staminaKey] = [
                        'used_stamina_total' => (float)($result['used_stamina_total'] ?? 0),
                        'current_stamina' => $result['current_stamina'] ?? null,
                    ];

                    // --- Log detalhado de staminaUpdates ---
                    Log::channel('battle_debug')->info("[finalizeItemCast][StaminaUpdates] {$role}", [
                        'battle' => $battleId,
                        'staminaKey' => $staminaKey,
                        'staminaUpdate' => $staminaUpdates[$staminaKey]
                    ]);
                }
            }

            $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
            $targetTypeStr = ($targetType === 'monster') ? 'Monstro' : 'Jogador';

            $actionInfoResult = '';

            if (isset($result['damage_dealt'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de {$result['damage_dealt']}";
            } elseif (isset($result['healed_amount'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de {$result['healed_amount']}";
            } elseif (isset($result['buff_applied'])) {
                $buff = $result['buff_applied'];
                $buffValue = $buff['bonus'] ?? ($buff['power'] ?? 0);
                $buffDuration = $buff['duration'] ?? '∞';
                $buffStat = $buff['stat'] ?? 'unknown';
                $actionInfoResult = "Buff aplicado: +{$buffValue} {$buffStat} por {$buffDuration} turnos";
            } elseif (isset($result['debuff_applied'])) {
                $debuff = $result['debuff_applied'];
                $debuffPower = $debuff['power'] ?? 0;
                $debuffDuration = $debuff['duration'] ?? '∞';
                $debuffStat = $debuff['stat'] ?? 'unknown';
                $actionInfoResult = "Debuff aplicado em {$targetName}: -{$debuffStat} ({$debuffPower}) por {$debuffDuration} turnos";
                $globalMessages[] = "⚡ Debuff de {$debuffStat} aplicado com sucesso em {$targetName}!";
            } elseif (!empty($result['debuff_failed'])) {
                $chance = $result['debuff_chance'] ?? null;
                $roll = $result['debuff_roll'] ?? null;
                $actionInfoResult = "Debuff falhou em {$targetName}";
                $globalMessages[] = "❌ Debuff em {$targetName} falhou (chance: " . round(($chance ?? 0) * 100, 1) . "%, roll: {$roll})";
            }

            if (!empty($result['someoneDied']) || !empty($result['target_died'])) {
                $globalMessages[] = "{$targetName} morreu!";
                $someoneDied = true;
            }

            $casterName = $caster['name'] ?? 'Desconhecido';
            $allResults[] = [
                'actionInfoUse' => "{$casterName} usou item {$itemId}",
                'actionInfoResult' => $actionInfoResult
            ];

            if (!empty($result['someoneDied'] ?? false)) {
                $targetName = $target['name'] ?? 'Desconhecido';
                $globalMessages[] = "$targetName morreu!";
                $someoneDied = true;
            }
        }
        // --- Players payload + stamina ---
        $playersPayload = [];
        foreach (Redis::hgetall("battle:$battleId:characters_data") as $playerId => $playerJson) {
            $playerData = is_string($playerJson) ? json_decode($playerJson, true) ?? [] : $playerJson;
            $playerStats = $playerData['stats'] ?? [];
            if (is_string($playerStats)) $playerStats = json_decode($playerStats, true) ?: [];

            $playerPayload = [
                'instanceId' => (string)$playerId,
                'currentHp' => (int)($playerStats['current_hp'] ?? 0),
            ];

            $playerStaminaKey = "character:{$playerId}";
            if (isset($staminaUpdates[$playerStaminaKey])) {
                $stRaw = Redis::hget("battle:$battleId:stamina_data", $playerStaminaKey);
                $stParsed = is_string($stRaw) ? json_decode($stRaw, true) ?? [] : $stRaw;

                $playerPayload['staminaData'] = [
                    'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                    'start_time' => (int)($stParsed['start_time'] ?? 0),
                    'used_stamina_total' => (float)$staminaUpdates[$playerStaminaKey]['used_stamina_total'],
                    'current_stamina' => $staminaUpdates[$playerStaminaKey]['current_stamina'] ?? null,
                ];
            }

            $playersPayload[] = $playerPayload;
        }

        // --- Monsters payload + stamina ---
        $enemiesPayload = [];
        foreach (Redis::hgetall("battle:$battleId:monsters") as $monsterId => $monsterJson) {
            $monsterData = is_string($monsterJson) ? json_decode($monsterJson, true) ?? [] : $monsterJson;
            $monsterStats = $monsterData['stats'] ?? [];
            if (is_string($monsterStats)) $monsterStats = json_decode($monsterStats, true) ?: [];

            $monsterPayload = [
                'instanceId' => (string)$monsterId,
                'isAlive' => ($monsterStats['current_hp'] ?? 0) > 0,
            ];

            $monsterStaminaKey = "monster:{$monsterId}";
            if (isset($staminaUpdates[$monsterStaminaKey])) {
                $stRaw = Redis::hget("battle:$battleId:stamina_data", $monsterStaminaKey);
                $stParsed = is_string($stRaw) ? json_decode($stRaw, true) ?? [] : $stRaw;

                $monsterPayload['staminaData'] = [
                    'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                    'start_time' => (int)($stParsed['start_time'] ?? 0),
                    'used_stamina_total' => (float)$staminaUpdates[$monsterStaminaKey]['used_stamina_total'],
                    'current_stamina' => $staminaUpdates[$monsterStaminaKey]['current_stamina'] ?? null,
                ];
            }

            $enemiesPayload[] = $monsterPayload;
        }

        $this->notifyBattle($battleId, 'result', [
            'players' => $playersPayload,
            'enemies' => $enemiesPayload,
            'actionInfoUse' => implode(' | ', array_column($allResults, 'actionInfoUse')),
            'actionInfoResult' => implode(' | ', array_column($allResults, 'actionInfoResult')),
            'globalMessages' => $globalMessages,
        ]);

        if ($casterCurrentStamina !== null) {
            Log::channel('battle_debug')->info("[finalizeItemCast] Stamina atual do caster", [
                'instanceId' => $caster['instanceId'] ?? '',
                'current_stamina' => $casterCurrentStamina
            ]);
        }

        // Limpa flags de execução
        if ($casterType === 'character') {
            Redis::del("battle:$battleId:skill_in_execution:{$casterInstanceId}");
            Redis::hdel("battle:$battleId:pending_items_data", $casterInstanceId);
            $characterId = Redis::hget("battle:$battleId:instance_map", $caster['instanceId']);
            try {
                $prepKey = "battle:{$characterId}:character:{$characterId}:consumables";
                $itemService->consumeItem($battleId, $caster['instanceId'], (int)$characterId, $itemId, 1, $prepKey);
            } catch (\Throwable $e) {
                Log::error("Falha ao decrementar item após uso", ['err' => $e->getMessage()]);
                // decidir rollback behavior: notificar jogador, etc.
            }
        } elseif ($casterType === 'monster') {
            $caster['isCasting'] = false;
            Redis::hset("battle:$battleId:monsters", $casterInstanceId, json_encode($caster));
        }

        return $someoneDied;
    }





    /**
     * Adiciona 'name' ao payload de entidade (character/monster) se possível,
     * lendo diretamente do Redis (ou deixando um valor padrão).
     * Recebe array por referência.
     */
    protected function enrichEntityWithName(string $battleId, array &$entity): void
    {
        if (!is_array($entity)) return;

        $type = $entity['type'] ?? 'character';
        $instanceId = (string)($entity['instanceId'] ?? '');

        try {
            if ($type === 'monster') {
                $raw = Redis::hget("battle:$battleId:monsters", $instanceId);
                $e = $raw ? json_decode($raw, true) : null;
                $entity['name'] = $e['name'] ?? ($entity['name'] ?? 'Monstro');
            } else {
                $raw = Redis::hget("battle:$battleId:characters_data", $instanceId);
                $e = $raw ? json_decode($raw, true) : null;
                $entity['name'] = $e['name'] ?? ($entity['name'] ?? 'Jogador');
            }
        } catch (\Throwable $e) {
            $entity['name'] = $entity['name'] ?? ($type === 'monster' ? 'Monstro' : 'Jogador');
        }
    }




    /**
     * Resolve e monta o payload da entidade usado como caster/target para applySkill.
     * - Se $type === 'character' => lê "battle:<battleId>:characters_data" field $instanceId
     * - Se $type === 'monster' => lê "battle:<battleId>:monsters" field $instanceId
     *
     * Retorna array com: ['instanceId' => string, 'stats' => array|null, 'type' => 'character'|'monster']
     * (garante que stats seja array decodificado quando possível)
     */
    protected function resolveEntityForTick(string $battleId, string $type, string $instanceId): array
    {
        if ($type === 'monster') {
            $raw = Redis::hget("battle:$battleId:monsters", $instanceId);
            $entity = $raw ? json_decode($raw, true) : null;
            $stats = $entity['stats'] ?? [];
            if (is_string($stats)) $stats = json_decode($stats, true) ?: [];
            return [
                'instanceId' => (string)$instanceId,
                'stats' => $stats,
                'type' => 'monster'
            ];
        } else {
            // character (default)
            $raw = Redis::hget("battle:$battleId:characters_data", $instanceId);
            $entity = $raw ? json_decode($raw, true) : null;
            $stats = $entity['stats'] ?? [];
            if (is_string($stats)) $stats = json_decode($stats, true) ?: [];
            return [
                'instanceId' => (string)$instanceId,
                'stats' => $stats,
                'type' => 'character'
            ];
        }
    }
    public function updateLastUpdate(string $battleId): void
    {
        Redis::set("battle:$battleId:last_update", time());
    }

    public function getActiveBattles(): array
    {
        return Redis::smembers('battles:active');
    }

    protected function resolveBehavior(string $type)
    {
        $map = [
            'goblin' => \App\Battle\MonstersBehavior\GoblinBehavior::class,
            'orc' => \App\Battle\MonstersBehavior\OrcBehavior::class,
        ];

        if (isset($map[$type])) {
            return app($map[$type]);
        }
    }

    protected function resolveTargets(array $action, array $caster, array $players, array $monsters, string $casterType): array
    {
        $targets = [];
        $targetType = $action['target_type'] ?? '';
        $targetId = isset($action['target_id']) ? (string)$action['target_id'] : null;
        $casterInstanceId = $caster['instanceId'] ?? ($action['caster_id'] ?? null);

        $addTarget = function ($refKey, $instanceId) use (&$targets) {
            // Define o tipo genérico a partir da refKey
            $category = $refKey === 'monsters' ? 'monster' : 'character';
            $targets[] = [
                'ref_key' => $refKey,
                'refInstanceId' => $instanceId,
                'category' => $category
            ];
        };

        switch ($targetType) {
            case 'self':
                $addTarget($casterType === 'monster' ? 'monsters' : 'characters_data', $casterInstanceId);
                break;

            case 'enemy':
                if ($casterType === 'character') {
                    if ($targetId !== null && isset($monsters[$targetId])) {
                        $addTarget('monsters', $targetId);
                    } else {
                        $firstKey = key($monsters);
                        if ($firstKey !== null) $addTarget('monsters', $firstKey);
                    }
                } else {
                    if ($targetId !== null && isset($players[$targetId])) {
                        $addTarget('characters_data', $targetId);
                    } else {
                        $firstKey = key($players);
                        if ($firstKey !== null) $addTarget('characters_data', $firstKey);
                    }
                }
                break;

            case 'ally':
                if ($casterType === 'character') {
                    if ($targetId !== null && isset($players[$targetId])) {
                        $addTarget('characters_data', $targetId);
                    }
                } else {
                    if ($targetId !== null && isset($monsters[$targetId])) {
                        $addTarget('monsters', $targetId);
                    }
                }
                break;

            case 'party':
                if ($casterType === 'character') {
                    foreach (array_keys($players) as $pId) {
                        $addTarget('characters_data', (string)$pId);
                    }
                } else {
                    foreach (array_keys($monsters) as $mId) {
                        $addTarget('monsters', (string)$mId);
                    }
                }
                break;

            case 'enemies':
                if ($casterType === 'character') {
                    foreach (array_keys($monsters) as $mId) {
                        $addTarget('monsters', (string)$mId);
                    }
                } else {
                    foreach (array_keys($players) as $pId) {
                        $addTarget('characters_data', (string)$pId);
                    }
                }
                break;

            default:
                if ($targetId !== null) {
                    if (isset($players[$targetId])) {
                        $addTarget('characters_data', $targetId);
                    } elseif (isset($monsters[$targetId])) {
                        $addTarget('monsters', $targetId);
                    }
                }
                break;
        }

        // LOG: Targets finais
        Log::channel('battle_debug')->info("[resolveTargets] Final targets", [
            'caster' => $caster['name'] ?? 'unknown',
            'targetType' => $targetType,
            'targets' => $targets
        ]);

        return $targets;
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
}
