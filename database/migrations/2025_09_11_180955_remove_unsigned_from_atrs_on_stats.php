<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            // Altera as colunas para INTEGER (signed) mantendo o tamanho original
            $table->integer('physical_defense')->change();
            $table->integer('magical_defense')->change();
        });
    }

    public function down(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            // Volta para unsigned
            $table->unsignedInteger('physical_defense')->change();
            $table->unsignedInteger('magical_defense')->change();
        });
    }
};
