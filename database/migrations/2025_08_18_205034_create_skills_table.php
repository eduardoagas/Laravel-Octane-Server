<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['physical', 'magical', 'heal', 'buff', 'debuff']);
            $table->integer('power')->nullable(); // dano ou cura
            $table->string('stat')->nullable(); // usado em buffs/debuffs
            $table->integer('bonus')->nullable(); // valor do buff/debuff
            $table->integer('duration')->nullable(); // duração do buff/debuff em turnos/segundos
            $table->integer('stamina_cost')->default(0);
            $table->integer('pre_delay')->default(0);  // milissegundos antes do efeito
            $table->integer('post_delay')->default(0); // milissegundos depois do efeito
            $table->integer('level')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
