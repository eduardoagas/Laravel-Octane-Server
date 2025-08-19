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
        // Cria nova instância da SoulGrid (baseada neste template)
        $newGrid = $this->replicate();
        $newGrid->soul_grid_inventory_id = null; // não está no inventory
        $newGrid->save();

        // Associa ao character
        $character->equipped_soul_grid_id = $newGrid->id;
        $character->save();

        return $newGrid;
    }
    public function character()
    {
        return $this->hasOne(Character::class, 'equipped_soul_grid_id');
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
