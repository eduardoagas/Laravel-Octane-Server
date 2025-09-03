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
        Schema::create('battle_pack_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consumable_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slot_index'); // posição (0..N)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battle_pack_slots');
    }
};
