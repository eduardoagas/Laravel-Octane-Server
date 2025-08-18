<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoulInventory extends Model
{
    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function souls()
    {
        return $this->hasMany(Soul::class);
    }
}
