<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Chave de acesso do documento (NF-e/NFC-e ~44; NFS-e pode ser maior). Identidade para upsert.
        DB::statement('ALTER TABLE documentos_fiscais MODIFY chave_acesso VARCHAR(64) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documentos_fiscais MODIFY chave_acesso VARCHAR(44) NULL');
    }
};
