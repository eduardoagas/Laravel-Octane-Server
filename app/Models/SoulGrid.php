<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoulGrid extends Model
{

    protected $fillable = [
        'name',
        'slots_count',
        'soul_grid_inventory_id',
    ];

    public function replicateForCharacter(Character $character): SoulGrid
    {
        // 1. Se houver grid equipado, subtrai seus stats do personagem
        if ($character->equipped_soul_grid_id) {
            $currentGrid = $character->equippedSoulGrid; // relação 'equippedSoulGrid' que busca pelo equipped_soul_grid_id
            if ($currentGrid && $currentGrid->stats) {
                $this->adjustCharacterStats($character, $currentGrid->stats, 'subtract');
            }
        }

        // 2. Clonar grid template
        $newGrid = $this->replicate();
        $newGrid->soul_grid_inventory_id = null;
        $newGrid->save();

        // 3. Clonar stats da grid, se existir
        if ($this->stats) {
            $newStats = $this->stats->replicate();
            $newStats->soul_grid_id = $newGrid->id;
            $newStats->save();

            // 4. Somar stats do novo grid ao personagem
            $this->adjustCharacterStats($character, $newStats, 'add');
        }

        // 5. Atualizar character
        $character->equipped_soul_grid_id = $newGrid->id;
        $character->save();

        return $newGrid;
    }

    /**
     * Ajusta os stats do character somando ou subtraindo os stats fornecidos.
     */
    protected function adjustCharacterStats(Character $character, Stats $stats, string $operation = 'add')
    {
        $characterStats = $character->stats;

        foreach ($stats->getAttributes() as $key => $value) {
            // Ignora chaves não numéricas ou relacionadas a IDs/foreign keys
            if (in_array($key, ['id', 'soul_grid_id', 'created_at', 'updated_at'])) continue;
            if (is_numeric($value)) {
                if ($operation === 'add') {
                    $characterStats->{$key} = ($characterStats->{$key} ?? 0) + $value;
                } else { // subtract
                    $characterStats->{$key} = ($characterStats->{$key} ?? 0) - $value;
                }
            }
        }

        $characterStats->save();
    }


    public function character()
    {
        return $this->hasOne(Character::class, 'equipped_soul_grid_id');
    }

    public function stats()
    {
        return $this->hasOne(Stats::class); // cada SoulGrid tem um conjunto de stats
    }
    public function soulGridInventory()
    {
        return $this->belongsTo(SoulGridInventory::class, 'soul_grid_inventory_id');
    }

    public function souls()
    {
        return $this->belongsToMany(Soul::class, 'soul_grid_soul')
            ->withTimestamps();
    }

    public function passiveBonuses()
    {
        return $this->hasOne(Stats::class); // FK direta
    }

    public function specialConditions()
    {
        return $this->hasMany(SpecialCondition::class);
    }
}
