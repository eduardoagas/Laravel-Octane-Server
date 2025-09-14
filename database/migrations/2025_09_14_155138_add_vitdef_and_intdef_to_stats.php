<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->integer('vitality_defense_bonus')->default(0)->after('physical_defense_bonus')->comment('Bônus de defesa física derivado da VIT');
            $table->integer('intelligence_magical_defense_bonus')->default(0)->after('magical_defense_bonus')->comment('Bônus de defesa mágica derivado da INT');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->dropColumn('vitality_defense_bonus');
            $table->dropColumn('intelligence_magical_defense_bonus');
        });
    }
};
