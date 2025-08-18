<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoulGrid extends Model
{
    public function inventory()
    {
        return $this->belongsTo(SoulGridInventory::class, 'soul_grid_inventory_id');
    }

    public function souls()
    {
        return $this->hasMany(Soul::class);
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
