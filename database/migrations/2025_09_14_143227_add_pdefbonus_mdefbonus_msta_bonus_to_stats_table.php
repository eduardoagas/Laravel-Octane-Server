<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->float('physical_defense_bonus')->default(0)->after('physical_defense');
            $table->float('magical_defense_bonus')->default(0)->after('magical_defense');
            $table->float('stamina_bonus')->default(0)->after('stamina');
        });
    }

    public function down(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->dropColumn(['physical_defense_bonus', 'magical_defense_bonus', 'stamina_bonus']);
        });
    }
};
