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
            $userIds = Redis::smembers("battle:$battleId:users");
            UnityConnectionRegistry::broadcastToUsers($userIds, $result);
        } catch (InsufficientStaminaException | SkillCooldownException $e) {
            $connection->send(json_encode(['error' => $e->getMessage()]));
        } catch (\Exception $e) {
            $connection->send(json_encode(['error' => 'Erro inesperado ao usar skill']));
        }
    }
}
