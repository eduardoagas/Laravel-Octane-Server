<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('skill_add_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('stat');    // Ex: physical_damage_resistance
            $table->double('value');    // Ex: 10 ou -5
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_add_effects');
    }
};
