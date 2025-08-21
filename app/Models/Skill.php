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
        'duration',
        'tick_interval',
        'tick_skill_id',
        'tick_skill_flag',
        'stackable',       // novo
        'max_stacks',      // novo
        'stack_behavior',  // novo
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
