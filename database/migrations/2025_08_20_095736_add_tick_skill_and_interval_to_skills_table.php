<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->unsignedBigInteger('tick_skill_id')->nullable(); // cria a coluna
            $table->integer('tick_interval')->nullable()->after('tick_skill');

            $table->foreign('tick_skill_id')->references('id')->on('skills')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['tick_skill', 'interval']);
        });
    }
};
