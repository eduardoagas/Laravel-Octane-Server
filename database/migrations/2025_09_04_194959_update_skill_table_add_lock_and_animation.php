<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->integer('animation_time')->default(0)->after('post_delay')
                ->comment('Tempo da animação em milissegundos');
            $table->integer('lock_time')->default(0)->after('animation_time')
                ->comment('Tempo que a skill "trava" o caster em milissegundos');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['animation_time', 'lock_time']);
        });
    }
};
