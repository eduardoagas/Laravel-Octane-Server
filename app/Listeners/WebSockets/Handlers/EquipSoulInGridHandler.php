<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Character;
use App\Models\Soul;
use App\Models\SoulGrid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;

/**
 * Payload esperado:
 * [
 *   'soul_id' => int,        // id da soul a equipar/mover
 *   'slot'    => int|null,   // slot desejado (opcional). se omisso, será appended
 *   'action'  => 'equip'|'move'|'unequip' (default 'equip'),
 *   'persist' => bool (opcional) // se true, sincroniza com DB
 * ]
 */
class EquipSoulInGridHandler
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $soulId = $payload['soul_id'] ?? null;
        $slot   = isset($payload['slot']) ? (int)$payload['slot'] : null;
        $action = $payload['action'] ?? 'equip';
        $persist = !empty($payload['persist']);

        // Busca character e battle
        $session = Redis::hgetall("session:$token");
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null;
        $battleId = $session['battle_instance_id'] ?? null;

        if (!$characterId || !$battleId) {
            $connection->send(json_encode(['error' => 'Invalid session or battle not active']));
            Log::warning("EquipSoul: missing character or battle in session", ['token' => $token, 'user' => $userId]);
            return;
        }

        if (!$soulId) {
            $connection->send(json_encode(['error' => 'soul_id is required']));
            return;
        }

        // Carrega estrutura da grid equipada do Redis, ou do DB se não existir no Redis
        $key = "battle:$battleId:character:{$characterId}:equipped_soul_grid";
        $gridJson = Redis::get($key);

        if ($gridJson) {
            $grid = json_decode($gridJson, true);
        } else {
            // fallback: carrega do DB e popula Redis (não bloqueia erro)
            $characterModel = Character::with(['equippedSoulGrid.souls.skills'])->find($characterId);
            if (!$characterModel || !$characterModel->equippedSoulGrid) {
                $connection->send(json_encode(['error' => 'No equipped grid found']));
                Log::info("EquipSoul: no equipped grid for character", ['character_id' => $characterId, 'battle' => $battleId]);
                return;
            }
            $grid = $characterModel->equippedSoulGrid->toArray();
            Redis::set($key, json_encode($grid, JSON_UNESCAPED_UNICODE));
        }

        // Normalize souls list
        $souls = $grid['souls'] ?? [];

        // Remove existing occurrence of soul in grid (if any)
        $souls = array_values(array_filter($souls, function ($s) use ($soulId) {
            return ((int)($s['id'] ?? 0)) !== (int)$soulId;
        }));

        if ($action === 'unequip') {
            // Apenas remove do grid
            $grid['souls'] = $souls;
            Redis::set($key, json_encode($grid, JSON_UNESCAPED_UNICODE));

            // Persistir se solicitado: remover pivot DB
            if ($persist && !empty($grid['id'])) {
                try {
                    $gridModel = SoulGrid::find($grid['id']);
                    if ($gridModel) {
                        $gridModel->souls()->detach($soulId);
                    }
                } catch (\Throwable $e) {
                    Log::error('EquipSoul: failed to detach soul on DB', ['error' => $e->getMessage(), 'grid_id' => $grid['id'], 'soul_id' => $soulId]);
                }
            }

            Log::info('Soul unequipped from grid', ['battle' => $battleId, 'character' => $characterId, 'soul' => $soulId]);
            $connection->send(json_encode(['event' => 'soul_unequipped', 'data' => ['soul_id' => $soulId]]));
            return;
        }

        // If equip/move: build soul payload to insert into grid's souls array
        // Try to fetch soul from DB to embed its minimal data (and skills if available)
        $soulModel = Soul::with('skills')->find($soulId);
        if (!$soulModel) {
            $connection->send(json_encode(['error' => 'Soul not found']));
            return;
        }

        $soulArray = $soulModel->toArray();
        // ensure there's a 'pivot' slot container for redis representation
        $pivot = ['slot' => $slot];

        $soulArray['pivot'] = $pivot;

        if ($slot === null) {
            // append to end
            $souls[] = $soulArray;
        } else {
            // ensure unique by slot: if some soul occupies the slot, shift or swap
            $occupiedIndex = null;
            foreach ($souls as $idx => $s) {
                if (isset($s['pivot']['slot']) && (int)$s['pivot']['slot'] === $slot) {
                    $occupiedIndex = $idx;
                    break;
                }
            }
            if ($occupiedIndex === null) {
                // place at end but set pivot slot
                $soulArray['pivot']['slot'] = $slot;
                $souls[] = $soulArray;
            } else {
                // replace the occupant
                $souls[$occupiedIndex] = $soulArray;
            }
        }

        // Reassign back to grid
        $grid['souls'] = array_values($souls);

        // Save back to Redis
        Redis::set($key, json_encode($grid, JSON_UNESCAPED_UNICODE));

        // Persist to DB if requested: attach soul to grid pivot with slot metadata
        if ($persist && !empty($grid['id'])) {
            try {
                $gridModel = SoulGrid::find($grid['id']);
                if ($gridModel) {
                    // use syncWithoutDetaching to keep existing and set pivot slot
                    $attachData = [$soulId => ['slot' => $slot]];
                    $gridModel->souls()->syncWithoutDetaching($attachData);
                }
            } catch (\Throwable $e) {
                Log::error('EquipSoul: failed to attach soul on DB', [
                    'error' => $e->getMessage(),
                    'grid_id' => $grid['id'] ?? null,
                    'soul_id' => $soulId,
                ]);
            }
        }

        Log::info('Soul equipped/moved in grid (in-redis)', ['battle' => $battleId, 'character' => $characterId, 'soul' => $soulId, 'slot' => $slot, 'persist' => $persist]);

        // reply to client with updated grid partial
        $connection->send(json_encode([
            'event' => 'equippedSoulGridUpdated',
            'data'  => [
                'character_id' => $characterId,
                'equipped_soul_grid' => $grid,
            ],
        ], JSON_UNESCAPED_UNICODE));
    }
}
