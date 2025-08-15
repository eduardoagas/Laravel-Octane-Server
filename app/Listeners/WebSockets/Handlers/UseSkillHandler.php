<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Services\UnityConnectionRegistry;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class UseSkillHandler implements HandlesUnityEvent
{
    private SkillService $skillService;

    public function __construct()
    {
        $this->skillService = new SkillService();
    }

    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $data = $payload['data'] ?? [];
        $skillId = $data['skill_id'] ?? null;
        $targetType = $data['target_type'] ?? null; // 'enemy' ou 'player'
        $targetId = $data['target_id'] ?? null;

        // Recupera sessão do jogador
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        Log::info("[USESKILLHANDLER] Usuário $characterId usando skill $skillId em batalha $battleId");

        if (!$battleId) {
            $connection->send(json_encode(['error' => 'Sessão inválida: battle_id ausente']));
            return;
        }
        if (!$characterId) {
            $connection->send(json_encode(['error' => 'Sessão inválida: character_id ausente']));
            return;
        }
        if ($skillId === null) {
            $connection->send(json_encode(['error' => 'skill_id ausente']));
            return;
        }

        // Dados do caster (sempre jogador neste handler)
        $characterData = Redis::hgetall("character_session:$characterId");
        $enemyData = null;

        if ($targetType === 'enemy' && $targetId) {
            $enemyJson = Redis::hget("battle:$battleId:monsters", $targetId);
            if (!$enemyJson) {
                $connection->send(json_encode(['error' => 'Inimigo não encontrado']));
                return;
            }
            $enemyData = json_decode($enemyJson, true);
            $enemyData['id'] = $targetId; // garante que tenha ID
            $enemyData['type'] = 'monster';
        }

        try {
            Log::info("Usuário $characterId usando skill $skillId em batalha $battleId");
            // Chamada para o serviço unificado
            $result = $this->skillService->applySkill(
                $characterData,
                $enemyData,
                $battleId,
                (int)$skillId,
                'character' // casterType
            );

            // Envia resultado para todos os usuários da batalha
            //UnityConnectionRegistry::broadcastToBattle($battleId, $result);
            $characterIds = Redis::smembers("{$battleId}:characters");

            foreach ($characterIds as $characterId) {
                // Recupera dados JSON do personagem
                $characterJson = Redis::hgetall("character:{$characterId}");

                if (empty($characterJson)) {
                    continue; // ignora se não achar dados
                }

                // Decodifica valores que foram salvos como JSON
                if (isset($characterJson['staminaData'])) {
                    $characterStaminaData = json_decode($characterJson['staminaData'], true);
                } else {
                    $characterStaminaData = null;
                }

                $playersPayload[] = [
                    'instanceId'  => (string) $characterId,
                    'currentHp'   => isset($characterJson['hp']) ? (int) $characterJson['hp'] : 0,
                    'staminaData' => $characterStaminaData,
                ];
            }

            $connection->send(json_encode([
                'event' => 'updateYourself',
                'channel' =>
                "character.{$characterId}",
                'data' => [
                    'players' => $playersPayload,
                    'general' => [
                        'actionInfoUse' => $result['action_info_use'],
                        'actionInfoResult' => $result['action_info_result'],
                    ]
                ]
            ]));
        } catch (InsufficientStaminaException | SkillCooldownException $e) {
            $connection->send(json_encode(['error' => $e->getMessage()]));
        } catch (\Exception $e) {
            $connection->send(json_encode(['error' => 'Erro inesperado ao usar skill']));
        }
    }
}
