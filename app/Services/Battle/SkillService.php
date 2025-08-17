<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class SkillService
{
    private StaminaService $staminaService;

    private array $skills = [
        0 => [
            'id' => 0,
            'name' => 'Attack',
            'type' => 'physical',
            'power' => 0,
            'stamina_cost' => 20,
            'pre_delay' => 0,
            'post_delay' => 500,
        ],
        1 => [
            'id' => 1,
            'name' => 'Fire Ball',
            'type' => 'magical',
            'power' => 25,
            'stamina_cost' => 17,
            'pre_delay' => 500,
            'post_delay' => 1000,
        ],
        2 => [
            'id' => 2,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'defense',
            'bonus' => 5,
            'duration' => 3,
            'stamina_cost' => 10,
            'pre_delay' => 300,
            'post_delay' => 500,
        ],
        3 => [
            'id' => 3,
            'name' => 'Heal',
            'type' => 'heal',
            'power' => 20, // quantidade de HP curada
            'stamina_cost' => 8,
            'pre_delay' => 400,
            'post_delay' => 700,
        ],
        4 => [
            'id' => 4,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'defense',
            'bonus' => 0,
            'duration' => 3,
            'stamina_cost' => 0,
            'pre_delay' => 300,
            'post_delay' => 500,
        ]
    ];

    public function __construct()
    {
        $this->staminaService = new StaminaService();
    }
    public function getSkillName(int $skillId): string
    {
        return $this->skills[$skillId]['name'] ?? 'Unknown Skill';
    }

    public function applySkill(
        array $caster,
        ?array $target,
        string $battleId,
        int $skillId,
        string $casterType, // 'character' ou 'monster'
        string $targetType,
    ): array {
        if (!isset($this->skills[$skillId])) {
            throw new \InvalidArgumentException("Skill $skillId not found");
        }
        $skill = $this->skills[$skillId];
        $casterId = $caster['instanceId'];

        // Cooldown global apenas para jogadores
        if ($casterType === 'character') {
            $this->checkCooldown($battleId, $casterId, $skill['post_delay']);
        }

        // Verifica stamina (leitura inicial)
        $currentStamina = $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType);
        if ($currentStamina < $skill['stamina_cost']) {
            // Se já estava insuficiente, corta aqui (mantém comportamento anterior).
            throw new InsufficientStaminaException("Stamina insuficiente ({$currentStamina} / {$skill['stamina_cost']})");
        }

        // Consome stamina (NOVO: operação atômica via Lua no StaminaService)
        // NOTE: consumeStamina agora retorna a stamina **após** o consumo (current_after) ou null em caso de falha/insuficiente
        $currentAfterConsumption = $this->staminaService->consumeStamina($battleId, $casterId, $skill['stamina_cost'], $casterType);

        // NOVO: trata condição de corrida (race) — se outro consumidor gastou antes, o Lua pode negar o consumo e retornar null
        if ($currentAfterConsumption === null) {
            // Lança exceção igual ao caso de insuficiência: mantém código chamador limpo
            throw new InsufficientStaminaException("Stamina insuficiente (race condition detectada ao tentar consumir)");
        }

        // Agora $currentAfterConsumption contém a stamina **após** o gasto aplicado
        // Não usamos/alteramos initial_stamina aqui (Opção A): o StaminaService atualiza used_stamina_total internamente.

        if (!$target) {
            throw new \InvalidArgumentException("Target is required for skill {$skill['name']}");
        }

        $targetKey = ($targetType ?? 'character') === 'monster' ? 'monsters' : 'characters_data';
        $redisKey = "battle:$battleId:$targetKey";

        $luaPath = storage_path("redis_scripts/battle_skill.lua");
        $luaScript = file_get_contents($luaPath);

        //$resultPayload = [];

        if ($skill['type'] === 'physical' || $skill['type'] === 'magical') {
            if (!$target) {
                throw new \InvalidArgumentException("Target is required for damage skills");
            }

            $attackAttribute = $skill['type'] === 'physical' ? 'pattack' : 'mattack';
            $baseAttack = $caster[$attackAttribute] ?? 0;
            $power = $skill['power'] + $baseAttack;
        } elseif ($skill['type'] === 'buff') {
            if (!$target) {
                throw new \InvalidArgumentException("Target is required for buff skills");
            }
            $power = $skill['bonus'];
        } elseif ($skill['type'] === 'heal') {
            if (!$target) {
                throw new \InvalidArgumentException("Target is required for heal skills");
            }
            $power = $skill['power'] + $caster['mattack'];
        }

        $resultJson = Redis::eval(
            $luaScript,
            1,
            $redisKey,
            $skill['type'],
            $casterId,
            $target['instanceId'],
            $power,
            $skill['stat'] ?? '',
            $skill['duration'] ?? 0
        );

        $result = json_decode($resultJson, true);

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        // NOVO: opcional — ler used_stamina_total atualizado do Redis para incluir no payload
        // Isso permite que o cliente sincronize usando start_time + initial_stamina + used_stamina_total
        $staminaField = "{$casterType}:{$casterId}";
        $staminaKey = "battle:$battleId:stamina_data";
        $staminaRaw = Redis::hget($staminaKey, $staminaField);
        $usedStaminaTotal = null;
        if ($staminaRaw) {
            $stParsed = json_decode($staminaRaw, true);
            $usedStaminaTotal = isset($stParsed['used_stamina_total']) ? (float)$stParsed['used_stamina_total'] : null;
        }

        // Retorna payload de resultado da skill
        // NOTA: "initial_stamina" era retornado antes; isso mudaria a semântica. Para compatibilidade mínima,
        // mantemos 'initial_stamina' mas agora ele representa o baseline (não atualizado pelo consumo).
        // NOVO: adicionamos 'current_stamina_after' (stamina após a aplicação do custo)
        // NOVO: adicionamos 'used_stamina_total' (útil para o cliente sincronizar caso queira)
        return [
            'battle_id' => $battleId,
            'caster_id' => $casterId,
            'skill_id' => $skillId,
            // NOVO/ATUALIZADO: current_stamina agora representa o valor **após** o consumo
            'current_stamina' => $currentAfterConsumption,
            // MANTIDO (mas atenção: semântica mudou — é o baseline inicial, não o snapshot após consumo)
            'initial_stamina' => null, // MANTIDO POR COMPATIBILIDADE (antigo uso). Coloque null para evitar confusão.
            'used_stamina_total' => $usedStaminaTotal, // NOVO: retorna o usado acumulado
            'pre_delay' => $skill['pre_delay'],
            'post_delay' => $skill['post_delay'],
            'someoneDied' => $result['target_died'] ?? false,
            'target_hp' => $result['current_hp'] ?? null,
            'damage_dealt' => $result['damage_dealt'] ?? null,
            'healed_amount' => $result['healed_amount'] ?? null,
            'buff_applied' => $result['buff_applied'] ?? null,
        ];
    }

    private function checkCooldown(string $battleId, string $casterId, int $postDelay)
    {
        $redisKey = "global_cooldown_at:{$battleId}:{$casterId}";
        $now = now()->timestamp;
        $readyAt = Redis::get($redisKey);
        if ($readyAt && $now < (int)$readyAt) {
            throw new SkillCooldownException("Skill em cooldown até " . date('H:i:s', (int)$readyAt));
        }
        Redis::set($redisKey, $now + (int)($postDelay / 1000));
    }

    private function saveEntityState(string $battleId, array $entity)
    {
        $instanceId = $entity['instanceId'] ?? null;

        if (!$instanceId) {
            Log::error("saveEntityState: entity missing instanceId", ['entity' => $entity]);
            return;
        }

        // Decide se é monstro ou personagem
        if (($entity['type'] ?? null) === 'monster' || isset($entity['monster_id'])) {
            Redis::hset("battle:{$battleId}:monsters", (string)$instanceId, json_encode($entity));

            // Log do HP atual do monstro
            $hp = $entity['hp'] ?? null;
            Log::info("Monster HP saved", [
                'instanceId' => $instanceId,
                'hp' => $hp,
                'battleId' => $battleId,
            ]);
        } else {
            Redis::hset("battle:{$battleId}:characters_data", (string)$instanceId, json_encode($entity));

            // Log do HP atual do personagem
            $hp = $entity['hp'] ?? null;
            Log::info("Character HP saved", [
                'instanceId' => $instanceId,
                'hp' => $hp,
                'battleId' => $battleId,
            ]);
        }
    }



    private function buildActionResultMessage(string $type, array $caster, ?array $target, array $resultPayload): string
    {
        $casterName = $caster['name'] ?? 'Unknown';
        $targetName = $target['name'] ?? 'Unknown';

        return match ($type) {
            'physical', 'magical' =>
            sprintf("%s inflicts %d damage on %s!", $casterName, $resultPayload['damage_dealt'] ?? 0, $targetName),
            'heal' =>
            sprintf("%s heals %s (%d HP)!", $casterName, $targetName, $resultPayload['healed_amount'] ?? 0),
            'buff' =>
            sprintf("%s applies %s buff to %s!", $casterName, ucfirst($resultPayload['buff_applied']['stat'] ?? 'unknown'), $targetName),
            default =>
            ''
        };
    }
}
