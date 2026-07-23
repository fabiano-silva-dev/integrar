<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // NF-e = 44 dígitos; NFS-e nacional = 50 dígitos.
        DB::statement('ALTER TABLE documentos_fiscais MODIFY chave_acesso VARCHAR(64) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documentos_fiscais MODIFY chave_acesso VARCHAR(44) NULL');
    }
};
