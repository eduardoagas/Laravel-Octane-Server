<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats', function (Blueprint $table) {
            $table->id();

            // Relacionamentos
            $table->foreignId('character_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('monster_id')->nullable()->constrained()->cascadeOnDelete();

            // Inteiros (sem casas decimais) - default 1
            $table->integer('hp')->default(100);
            $table->integer('level')->default(1);
            $table->integer('strength')->default(1);
            $table->integer('intelligence')->default(1);
            $table->integer('vitality')->default(1);
            $table->integer('dexterity')->default(1);
            $table->integer('luck')->default(1);
            $table->integer('wisdom')->default(1);
            $table->integer('strength_bonus')->default(1);
            $table->integer('intelligence_bonus')->default(1);
            $table->integer('vitality_bonus')->default(1);
            $table->integer('dexterity_bonus')->default(1);
            $table->integer('luck_bonus')->default(1);
            $table->integer('wisdom_bonus')->default(1);

            // Stamina - default 50
            $table->double('stamina')->default(50);

            // Restante - default 0
            $table->double('nstatus_resistance')->default(0);
            $table->double('nstats_potency')->default(0);
            $table->double('burn_resistance')->default(0);
            $table->double('freeze_resistance')->default(0);
            $table->double('bury_resistance')->default(0);
            $table->double('shock_resistance')->default(0);
            $table->double('silence_resistance')->default(0);
            $table->double('blind_resistance')->default(0);
            $table->double('stun_resistance')->default(0);
            $table->double('poison_resistance')->default(0);
            $table->double('paralyze_resistance')->default(0);
            $table->double('zombie_resistance')->default(0);
            $table->double('virus_resistance')->default(0);
            $table->double('death_resistance')->default(0);
            $table->double('bleed_resistance')->default(0);
            $table->double('leak_resistance')->default(0);
            $table->double('pierce_resistance')->default(0);
            $table->double('shrink_resistance')->default(0);
            $table->double('confusion_resistance')->default(0);
            $table->double('absolute_dodge')->default(0);
            $table->double('nstatus_potency')->default(0);
            $table->double('pstatus_potency')->default(0);
            $table->double('elemental_resistance')->default(0);
            $table->double('elemental_potency')->default(0);
            $table->double('pve_resistance')->default(0);
            $table->double('pvp_resistance')->default(0);
            $table->double('pve_damage')->default(0);
            $table->double('physical_damage_bonus')->default(0);
            $table->double('magical_damage_bonus')->default(0);
            $table->double('healing_potency')->default(0);
            $table->double('recover_potency')->default(0);
            $table->double('fire_resistance')->default(0);
            $table->double('lightning_resistance')->default(0);
            $table->double('water_resistance')->default(0);
            $table->double('earth_resistance')->default(0);
            $table->double('holy_resistance')->default(0);
            $table->double('dark_resistance')->default(0);
            $table->double('fire_potency')->default(0);
            $table->double('lightning_potency')->default(0);
            $table->double('earth_potency')->default(0);
            $table->double('water_potency')->default(0);
            $table->double('holy_potency')->default(0);
            $table->double('dark_potency')->default(0);
            $table->double('dex_break_resistance')->default(0);
            $table->double('def_break_resistance')->default(0);
            $table->double('mdef_break_resistance')->default(0);
            $table->double('defense_bonus')->default(0);
            $table->double('mdefense_bonus')->default(0);
            $table->double('damage_break_resistance')->default(0);
            $table->double('mdamage_break_resistance')->default(0);
            $table->double('critical_chance')->default(0);
            $table->double('critical_damage_resistance')->default(0);
            $table->double('critical_chance_break')->default(0);
            $table->double('equip_break_resistance')->default(0);
            $table->double('weapon_break_resistance')->default(0);
            $table->double('equip_break_potency')->default(0);
            $table->double('wisdom_break_resistance')->default(0);
            $table->double('armor_break_resistance')->default(0);
            $table->double('shield_break_resistance')->default(0);
            $table->double('acc_break_resistance')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stats');
    }
};
