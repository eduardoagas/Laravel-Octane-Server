<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // adiciona a coluna e a constraint
            $table->foreignId('equipped_soul_grid_id')
                ->nullable()
                ->constrained('soul_grids')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // rollback: precisa dropar a FK antes da coluna
            $table->dropForeign(['equipped_soul_grid_id']);
            $table->dropColumn('equipped_soul_grid_id');
        });
    }
};
