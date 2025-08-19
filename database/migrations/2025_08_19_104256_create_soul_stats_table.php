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
        Schema::create('soul_stats', function (Blueprint $table) {
            $table->foreignId('soul_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stat_id')->constrained('stats')->cascadeOnDelete();
            $table->integer('value')->nullable(); // override opcional
            $table->timestamps();

            $table->primary(['soul_id', 'stat_id']); // chave composta
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('soul_stats');
    }
};
