<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = [
        'name',
        'type',
        'power',
        'stat',
        'bonus',
        'duration',
        'stamina_cost',
        'pre_delay',
        'post_delay',
        'level'
    ];

    // Caso futuramente queira relacionar com Souls:
    // public function souls()
    // {
    //     return $this->belongsToMany(Soul::class, 'soul_skills')->withTimestamps();
    // }
}
