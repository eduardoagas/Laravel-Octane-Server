<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stats extends Model
{
    protected $fillable = [
        'hp',
        'level',
        'stamina',
        'strength',
        'intelligence',
        'vitality',
        'dexterity',
        'luck',
        'wisdom',
        'nstatus_resistance',
        'nstats_potency',
        'burn_resistance',
        'freeze_resistance',
        'bury_resistance',
        'shock_resistance',
        'silence_resistance',
        'blind_resistance',
        'stun_resistance',
        'poison_resistance',
        'paralyze_resistance',
        'zombie_resistance',
        'virus_resistance',
        'death_resistance',
        'bleed_resistance',
        "leak_resistance",
        "pierce_resistance",
        "shrink_resistance",
        'confusion_resistance',
        'absolute_dodge',
        'nstatus_potency',
        'pstatus_potency',
        'elemental_resistance',
        'elemental_potency',
        'pve_resistance',
        'pvp_resistance',
        'pve_damage',
        'pve_resistance',
        'healing_potency',
        'recover_potency',
        'fire_resistance',
        'lightning_resistance',
        'water_resistance',
        'earth_resistance',
        'holy_resistance',
        'dark_resistance',
        'fire_potency',
        'lightning_potency',
        'earth_potency',
        'water_potency',
        'holy_potency',
        'dark_potency',
        'dex_break_resistance',
        'def_break_resistance',
        'mdef_break_resistance',
        'physical_defense',
        'magical_defense',
        'damage_break_resistance',
        'mdamage_break_resistance',
        'critical_chance',
        'critical_damage_resistance',
        'critical_chance_break',
        'equip_break_resistance',
        'weapon_break_resistance',
        'equip_break_potency',
        'wisdom_break_resistance',
        'armor_break_resistance',
        'shield_break_resistance',
        'acc_break_resistance'
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function monster()
    {
        return $this->belongsTo(Monster::class);
    }

    public function soulGrid()
    {
        return $this->belongsTo(SoulGrid::class);
    }
}
