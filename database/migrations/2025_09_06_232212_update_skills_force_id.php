<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remove o default de sequence do id para permitir inserção manual de IDs
        DB::statement('ALTER TABLE skills ALTER COLUMN id DROP DEFAULT');
    }

    public function down(): void
    {
        // Restaura auto-increment
        DB::statement("CREATE SEQUENCE skills_id_seq OWNED BY skills.id");
        DB::statement("ALTER TABLE skills ALTER COLUMN id SET DEFAULT nextval('skills_id_seq')");
    }
};
