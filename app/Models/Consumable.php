<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consumable extends Model
{
    protected $fillable = [
        'name',
        'description',
        'effect_type',
        'effect_value',
    ];

    /**
     * Relacionamento com itens de inventário (ConsumableItem).
     * Um consumable pode estar em vários inventários de jogadores.
     */
    public function items()
    {
        return $this->hasMany(ConsumableItem::class);
    }
}
