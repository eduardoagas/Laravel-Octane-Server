<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialCondition extends Model
{
    public function soulGrid()
    {
        return $this->belongsTo(SoulGrid::class);
    }
}
