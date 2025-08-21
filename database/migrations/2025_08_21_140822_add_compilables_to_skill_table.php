<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->boolean('stackable')->default(false)->after('description')
                ->comment('Se a skill pode acumular stacks');
            $table->integer('max_stacks')->default(1)->after('stackable')
                ->comment('Número máximo de stacks que podem ser aplicados');
            $table->enum('stack_behavior', ['add', 'refresh', 'replace'])
                ->default('replace')
                ->after('max_stacks')
                ->comment('Como o stack influencia a skill: add, refresh ou replace');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['stackable', 'max_stacks', 'stack_behavior']);
        });
    }
};
