<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Remover a constraint de enum (check constraint)
        DB::statement('ALTER TABLE skills DROP CONSTRAINT IF EXISTS skills_type_check');

        // 2. Alterar a coluna para string simples
        Schema::table('skills', function (Blueprint $table) {
            $table->string('type')->change();
        });
    }

    public function down(): void
    {
        // Reverter para enum (com os valores que você tinha antes)
        Schema::table('skills', function (Blueprint $table) {
            $table->enum('type', [
                'damage',
                'buff',
                'debuff',
                'heal',
                'dot',
                'percentageDamage',
                'purePercentageDamage',
            ])->change();
        });
    }
};
