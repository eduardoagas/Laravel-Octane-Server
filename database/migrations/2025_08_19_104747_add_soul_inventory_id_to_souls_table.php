<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('souls', function (Blueprint $table) {
            $table->foreignId('soul_inventory_id')
                ->nullable()
                ->constrained() // por padrão, referencia 'soul_inventories' (plural da tabela)
                ->cascadeOnDelete(); // se o inventory for deletado, deleta a soul também
        });
    }

    public function down(): void
    {
        Schema::table('souls', function (Blueprint $table) {
            $table->dropForeign(['soul_inventory_id']);
            $table->dropColumn('soul_inventory_id');
        });
    }
};
