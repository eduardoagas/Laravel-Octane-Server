<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Monster extends Model
{
    protected $fillable = [
        'name',
        'type',
        // outros campos do monstro...
    ];

    public function stats()
    {
        return $this->hasOne(Stats::class);
    }
}
