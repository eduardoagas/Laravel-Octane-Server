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
        Schema::create('soul_grids', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('slots_count')->default(0);
            $table->foreignId('soul_grid_inventory_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('soul_grids');
    }
};
