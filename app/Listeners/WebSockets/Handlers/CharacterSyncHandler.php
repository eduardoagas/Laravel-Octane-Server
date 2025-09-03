<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Character;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Contracts\Connection;

class CharacterSyncHandler
{
    /**
     * Atualiza stats + soul grids do personagem
     */
    public function handle(int $characterId, Connection $connection): void
    {
        $character = Character::with(['stats', 'equippedSoulGrid.souls.skills'])->find($characterId);

        if (!$character) {
            Log::warning("Character not found for CharacterSyncHandler", ['character_id' => $characterId]);
            return;
        }

        // Stats
        $stats = $character->stats?->toArray() ?? [];
        $stats['current_hp'] = $stats['hp'] ?? 0;

        // SoulGrid
        $soulsArray = [];
        $tickSkillsForInstance = [];

        if ($character->equippedSoulGrid) {
            foreach ($character->equippedSoulGrid->souls as $soul) {
                $skillsArray = [];
                foreach ($soul->skills as $skill) {
                    $skillArr = [
                        'id' => $skill->id,
                        'name' => $skill->name,
                        'type' => $skill->type,
                        'power' => $skill->power ?? 0,
                        'stamina_cost' => $skill->stamina_cost ?? 0,
                        'pre_delay' => $skill->pre_delay ?? 0,
                        'post_delay' => $skill->post_delay ?? 0,
                        'duration' => $skill->duration,
                        'level' => $skill->level ?? 1,
                        'stat' => $skill->stat,
                        'tick_interval' => $skill->tick_interval ?? null,
                        'tick_skill_id' => $skill->tick_skill_id ?? null,
                        'tick_skill_flag' => $skill->tick_skill_flag ?? false,
                    ];

                    if (!empty($skill->tick_skill_flag) || !empty($skill->tick_skill_id)) {
                        $tickSkillsForInstance[$skill->id] = $skillArr;
                    }

                    $skillsArray[] = $skillArr;
                }

                $soulsArray[] = [
                    'id' => $soul->id,
                    'name' => $soul->name,
                    'skills' => $skillsArray,
                ];
            }
        }

        // Redis: snapshot da grid do personagem
        $instanceGridKey = "character:{$characterId}:equipped_soul_grid";
        Redis::set($instanceGridKey, json_encode($soulsArray, JSON_UNESCAPED_UNICODE));

        // Redis: tick skills
        $instanceTickSkillsKey = "character:{$characterId}:tick_skills";
        Redis::set($instanceTickSkillsKey, json_encode(array_values($tickSkillsForInstance), JSON_UNESCAPED_UNICODE));

        // Payload
        $payload = [
            'event' => 'updateYourself',
            'channel' => "character.{$characterId}",
            'data' => [
                'stats' => $stats,
                'equipped_soul_grid' => $soulsArray,
            ],
        ];

        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE));
        Log::info("CharacterSyncHandler: payload enviado", ['character_id' => $characterId]);
    }
}
