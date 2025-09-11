<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->double('physical_damage_resistance')->default(0)->after('magical_defense');
            $table->double('magical_damage_resistance')->default(0)->after('physical_damage_resistance');
        });
    }

    public function down(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->dropColumn('physical_damage_resistance');
            $table->dropColumn('magical_damage_resistance');
        });
    }
};
