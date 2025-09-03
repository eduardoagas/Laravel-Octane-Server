<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableItem extends Model
{
    protected $fillable = [
        'consumables_inventory_id',
        'consumable_id',
        'quantity',
    ];

    public function inventory()
    {
        return $this->belongsTo(ConsumablesInventory::class);
    }

    public function consumable()
    {
        return $this->belongsTo(Consumable::class);
    }

    public function battlePackSlots()
    {
        return $this->hasMany(BattlePackSlot::class);
    }
}
