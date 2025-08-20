<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = [
        'name',
        'type',
        'power',
        'stamina_cost',
        'pre_delay',
        'post_delay',
        'level',
        'stat',
        'bonus',
        'duration',
        'interval',
        'tick_skill',
    ];

    public function souls()
    {
        return $this->belongsToMany(Soul::class, 'soul_skill')
            ->withTimestamps();
    }

    public function tickSkill()
    {
        return $this->belongsTo(Skill::class, 'tick_skill_id');
    }
}
