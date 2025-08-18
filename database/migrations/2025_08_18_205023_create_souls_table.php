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
        Schema::create('souls', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('monster_source_id')->nullable(); // FK opcional se você tiver tabela monsters
            $table->json('skills')->nullable(); // skills pré-definidas
            $table->json('slots')->nullable();

            // Relações
            $table->foreignId('soul_inventory_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('soul_grid_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('souls');
    }
};
