<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BattlePack extends Model
{
    protected $fillable = ['character_id'];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function slots()
    {
        return $this->hasMany(BattlePackSlot::class);
    }

    public function hasFreeSlot(): bool
    {
        return $this->slots()->count() < $this->max_slots;
    }
}
