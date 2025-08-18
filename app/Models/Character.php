<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Character extends Model
{
    protected $fillable = [
        'user_id',
        'name',
    ];

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
