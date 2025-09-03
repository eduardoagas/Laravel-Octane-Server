<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BattlePackSlot extends Model
{
    protected $fillable = ['battle_pack_id', 'consumable_item_id', 'slot_index'];

    public function battlePack()
    {
        return $this->belongsTo(BattlePack::class);
    }

    public function consumableItem()
    {
        return $this->belongsTo(ConsumableItem::class);
    }

    public function consumable()
    {
        // acesso direto ao catálogo via item
        return $this->hasOneThrough(
            Consumable::class,
            ConsumableItem::class,
            'id',                // chave local em consumable_items
            'id',                // chave local em consumables
            'consumable_item_id', // FK em battle_pack_slots
            'consumable_id'      // FK em consumable_items
        );
    }
}
