<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Character extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'equipped_soul_grid_id', // já existe na migration
        'preferred_soul_slot',   // novo campo para definir slot inicial da soul ativa
    ];

    public function equippedSoulGrid()
    {
        return $this->belongsTo(SoulGrid::class, 'equipped_soul_grid_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stats()
    {
        return $this->hasOne(Stats::class);
    }

    public function soulGridInventory()
    {
        return $this->hasOne(SoulGridInventory::class);
    }

    public function soulInventory()
    {
        return $this->hasOne(SoulInventory::class);
    }
}

