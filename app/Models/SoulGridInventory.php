<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoulGridInventory extends Model
{

    protected $fillable = ['character_id']; // permite criação simples
    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function soulGrids()
    {
        return $this->hasMany(SoulGrid::class);
    }
}
