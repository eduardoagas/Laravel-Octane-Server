<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Soul extends Model
{
    protected $casts = ['skills' => 'array', 'slots' => 'array'];

    public function activationBonuses()
    {
        return $this->belongsToMany(Stats::class, 'soul_stats')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function soulInventory()
    {
        return $this->belongsTo(SoulInventory::class);
    }

    public function soulGrid()
    {
        return $this->belongsTo(SoulGrid::class);
    }
}
