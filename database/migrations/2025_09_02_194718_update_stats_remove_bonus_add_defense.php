<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            // remover campos _bonus
            $table->dropColumn([
                'strength_bonus',
                'intelligence_bonus',
                'vitality_bonus',
                'dexterity_bonus',
                'luck_bonus',
                'wisdom_bonus',
                'defense_bonus',
                'mdefense_bonus',
                'physical_damage_bonus',
                'magical_damage_bonus',
            ]);

            // adicionar os novos campos renomeados
            $table->unsignedInteger('physical_defense')->default(0)->after('mdamage_break_resistance');
            $table->unsignedInteger('magical_defense')->default(0)->after('physical_defense');
        });
    }

    public function down(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            // re-adicionar campos antigos
            $table->unsignedInteger('strength_bonus')->default(0)->after('strength');
            $table->unsignedInteger('intelligence_bonus')->default(0)->after('intelligence');
            $table->unsignedInteger('vitality_bonus')->default(0)->after('vitality');
            $table->unsignedInteger('dexterity_bonus')->default(0)->after('dexterity');
            $table->unsignedInteger('luck_bonus')->default(0)->after('luck');
            $table->unsignedInteger('wisdom_bonus')->default(0)->after('wisdom');
            $table->unsignedInteger('defense_bonus')->default(0)->after('mdamage_break_resistance');
            $table->unsignedInteger('mdefense_bonus')->default(0)->after('defense_bonus');
            $table->unsignedInteger('physical_damage_bonus')->default(0)->after('pve_resistance');
            $table->unsignedInteger('magical_damage_bonus')->default(0)->after('physical_damage_bonus');

            // remover os novos campos
            $table->dropColumn(['physical_defense', 'magical_defense']);
        });
    }
};
