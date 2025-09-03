<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumablesInventory extends Model
{
    protected $fillable = ['character_id']; // permite criação simples
    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function items()
    {
        return $this->hasMany(ConsumableItem::class);
    }
}
