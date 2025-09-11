<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillAddEffect extends Model
{
    protected $fillable = [
        'skill_id',
        'stat',
        'value',
    ];

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
