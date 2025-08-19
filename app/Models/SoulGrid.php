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
        // 1. Clonar grid template
        $newGrid = $this->replicate();
        $newGrid->soul_grid_inventory_id = null;
        $newGrid->save();

        // 2. Clonar stats da grid, se existir
        if ($this->stats) {
            $newStats = $this->stats->replicate();
            $newStats->soul_grid_id = $newGrid->id;
            $newStats->save();
        }

        // 3. Atualizar character
        $character->equipped_soul_grid_id = $newGrid->id;
        $character->save();

        return $newGrid;
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
