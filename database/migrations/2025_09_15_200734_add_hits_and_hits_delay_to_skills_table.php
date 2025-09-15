<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->unsignedInteger('hits')->default(1); // substitua 'some_existing_column' pela coluna apropriada
            $table->unsignedInteger('hit_delay')->default(0)->after('hits')->comment('Delay entre hits em ms');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['hits', 'hit_delay']);
        });
    }
};
