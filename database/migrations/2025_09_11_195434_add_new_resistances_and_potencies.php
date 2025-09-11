<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->double('poison_element_potency')->default(0);
            $table->double('poison_element_resistance')->default(0);
            $table->double('non_elemental_potency')->default(0);
            $table->double('non_elemental_resistance')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->dropColumn([
                'poison_element_potency',
                'poison_element_resistance',
                'non_elemental_potency',
                'non_elemental_resistance'
            ]);
        });
    }
};
