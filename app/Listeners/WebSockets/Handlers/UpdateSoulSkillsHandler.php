<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Character;
use App\Models\Skill;
use App\Models\Soul;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;

/**
 * Payload esperado:
 * [
 *   'soul_id' => int,
 *   'skills'  => [int, int, ...], // lista de skill ids (replace)
 *   'persist' => bool (opcional)  // se true, sincroniza com DB pivot soul_skill
 * ]
 */
class UpdateSoulSkillsHandler
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $soulId = $payload['soul_id'] ?? null;
        $skillIds = $payload['skills'] ?? null;
        $persist = !empty($payload['persist']);

        // validações básicas
        if (!$soulId || !is_array($skillIds)) {
            $connection->send(json_encode(['error' => 'Invalid payload: soul_id and skills[] required']));
            return;
        }

        // busca session
        $session = Redis::hgetall("session:$token");
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null;
        $battleId = $session['battle_instance_id'] ?? null;

        if (!$characterId || !$battleId) {
            $connection->send(json_encode(['error' => 'Invalid session or battle not active']));
            Log::warning("UpdateSoulSkills: missing character or battle in session", ['token' => $token, 'user' => $userId]);
            return;
        }

        // carregar grid da redis (fallback para DB se necessário)
        $key = "battle:$battleId:character:{$characterId}:equipped_soul_grid";
        $gridJson = Redis::get($key);

        if ($gridJson) {
            $grid = json_decode($gridJson, true);
        } else {
            $characterModel = Character::with(['equippedSoulGrid.souls.skills'])->find($characterId);
            if (!$characterModel || !$characterModel->equippedSoulGrid) {
                $connection->send(json_encode(['error' => 'No equipped grid found']));
                return;
            }
            $grid = $characterModel->equippedSoulGrid->toArray();
            Redis::set($key, json_encode($grid, JSON_UNESCAPED_UNICODE));
        }

        // find the soul inside the grid
        $found = false;
        foreach ($grid['souls'] as $idx => $s) {
            if ((int)($s['id'] ?? 0) === (int)$soulId) {
                $found = true;

                // load skill objects to embed into redis structure (so client has full skill metadata)
                $skills = Skill::whereIn('id', $skillIds)->get()->map(function ($sk) {
                    return $sk->toArray();
                })->values()->all();

                // replace in-memory in grid structure
                $grid['souls'][$idx]['skills'] = $skills;

                break;
            }
        }

        if (!$found) {
            $connection->send(json_encode(['error' => 'Soul not equipped in the grid']));
            return;
        }

        // write back to redis
        Redis::set($key, json_encode($grid, JSON_UNESCAPED_UNICODE));

        // optionally persist to DB (sync pivot soul_skill)
        if ($persist) {
            try {
                $soulModel = Soul::find($soulId);
                if ($soulModel) {
                    $soulModel->skills()->sync($skillIds);
                }
            } catch (\Throwable $e) {
                Log::error('UpdateSoulSkills: failed to sync skills to DB', [
                    'error' => $e->getMessage(),
                    'soul_id' => $soulId,
                    'skills' => $skillIds,
                ]);
            }
        }

        Log::info('Updated soul skills in battle cache', ['battle' => $battleId, 'character' => $characterId, 'soul' => $soulId, 'persist' => $persist]);

        // respond to client with updated soul (or full grid)
        $connection->send(json_encode([
            'event' => 'soulSkillsUpdated',
            'data'  => [
                'character_id' => $characterId,
                'soul_id'      => $soulId,
                'skills'       => $grid['souls'][$idx]['skills'],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }
}
