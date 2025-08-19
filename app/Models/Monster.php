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

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'monster_skill', 'monster_id', 'skill_id');
    }
}
