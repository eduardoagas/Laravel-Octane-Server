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
            $table->foreignId('soul_grid_id')
                ->nullable()
                ->constrained('soul_grids')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stats', function (Blueprint $table) {
            $table->dropForeign(['soul_grid_id']);
            $table->dropColumn('soul_grid_id');
        });
    }
};
