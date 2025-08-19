<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Soul extends Model
{
    protected $casts = [
        'slots_count' => 'integer',
    ];

    public function activationBonuses()
    {
        return $this->belongsToMany(Stats::class, 'soul_stats')
            ->withPivot('value')
            ->withTimestamps();
    }

    protected $fillable = [
        'name',
        'monster_source_id',
        'slots_count',
        'character_id',
        'soul_inventory_id',
    ];

    public function soulInventory()
    {
        return $this->belongsTo(SoulInventory::class);
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'soul_skill')
            ->withTimestamps();
    }


    public function soulGrids()
    {
        return $this->belongsToMany(SoulGrid::class, 'soul_grid_soul')
            ->withTimestamps();
    }
}
