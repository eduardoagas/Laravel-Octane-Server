<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Skill;
use App\Models\Character;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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
                        'id'             => $skill->id,
                        'name'           => $skill->name,
                        'type'           => $skill->type,
                        'power'          => $skill->power ?? 0,
                        'stamina_cost'   => $skill->stamina_cost ?? 0,
                        'pre_delay'      => $skill->pre_delay ?? 0,
                        'post_delay'     => $skill->post_delay ?? 0,
                        'duration'       => $skill->duration,
                        'level'          => $skill->level ?? 1,
                        'stat'           => $skill->stat,
                        'tick_interval'  => $skill->tick_interval ?? null,
                        'tick_skill_id'  => $skill->tick_skill_id ?? null,
                        'tick_skill_flag' => $skill->tick_skill_flag ?? false,
                    ];

                    // 1️⃣ Skill de tick direta
                    if (!empty($skill->tick_skill_flag)) {
                        $tickSkillsForInstance[$skill->id] = $skillArr;
                    }

                    // 2️⃣ Se skill possui tick_skill_id, adiciona a skill de tick correspondente
                    if (!empty($skill->tick_skill_id)) {
                        $tickSkill = Skill::find($skill->tick_skill_id);
                        if ($tickSkill) {
                            $tickSkillsForInstance[$tickSkill->id] = [
                                'id'             => $tickSkill->id,
                                'name'           => $tickSkill->name,
                                'type'           => $tickSkill->type,
                                'power'          => $tickSkill->power ?? 0,
                                'stamina_cost'   => $tickSkill->stamina_cost ?? 0,
                                'pre_delay'      => $tickSkill->pre_delay ?? 0,
                                'post_delay'     => $tickSkill->post_delay ?? 0,
                                'duration'       => $tickSkill->duration,
                                'level'          => $tickSkill->level ?? 1,
                                'stat'           => $tickSkill->stat,
                                'tick_interval'  => $tickSkill->tick_interval ?? null,
                                'tick_skill_id'  => $tickSkill->tick_skill_id ?? null,
                                'tick_skill_flag' => $tickSkill->tick_skill_flag ?? false,
                            ];
                        }
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
        $instanceGridKey = "world:{$characterId}:character:{$characterId}:equipped_soul_grid";
        Redis::set($instanceGridKey, json_encode($soulsArray, JSON_UNESCAPED_UNICODE));

        // Redis: tick skills
        $instanceTickSkillsKey = "world:{$characterId}:character:{$characterId}:tick_skills";
        Redis::set($instanceTickSkillsKey, json_encode(array_values($tickSkillsForInstance), JSON_UNESCAPED_UNICODE));

        //$normalizedStats = $this->normalizeStatsValue($stats);
        //$normalizedSoulsArray = $this->normalizeStatsValue($soulsArray);
        // Payload
        $payload = [
            'event' => 'updateYourselfWorld',
            'channel' => "character.{$characterId}",
            'data' => [
                'stats' => $stats,
                'equippedSoulGrid' => $soulsArray,

            ]
        ];

        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE));
        Log::info("CharacterSyncHandler: payload enviado", ['character_id' => $characterId]);
    }
}
