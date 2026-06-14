<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tenantTables = [
        'empresas',
        'importacoes',
        'lancamentos',
        'regras_amarracoes_descricoes',
        'layouts_importacao',
        'historicos_padrao_layout',
        'terceiros',
        'conversoes_extrato',
        'amarracoes',
    ];

    public function up(): void
    {
        Schema::table('empresas_operadoras', function (Blueprint $table) {
            if (!Schema::hasColumn('empresas_operadoras', 'ativo')) {
                $table->boolean('ativo')->default(true)->after('configuracoes');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'empresa_operadora_id')) {
                $table->foreignId('empresa_operadora_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('empresas_operadoras')
                    ->nullOnDelete();
            }
        });

        foreach ($this->tenantTables as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'empresa_operadora_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('empresa_operadora_id')
                    ->nullable()
                    ->constrained('empresas_operadoras')
                    ->cascadeOnDelete();
                $table->index('empresa_operadora_id');
            });
        }

        $this->addSuperAdminRole();

        $operadoraId = $this->criarOperadoraPadrao();
        $this->associarDadosLegados($operadoraId);

        if (Schema::hasTable('empresas') && Schema::hasColumn('empresas', 'cnpj')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->dropUnique(['cnpj']);
                $table->unique(['empresa_operadora_id', 'cnpj']);
            });
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            foreach ($this->tenantTables as $tableName) {
                if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'empresa_operadora_id')) {
                    continue;
                }

                DB::statement("ALTER TABLE `{$tableName}` MODIFY `empresa_operadora_id` BIGINT UNSIGNED NOT NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tenantTables) as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'empresa_operadora_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('empresa_operadora_id');
            });
        }

        if (Schema::hasTable('empresas') && Schema::hasColumn('empresas', 'empresa_operadora_id')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->dropUnique(['empresa_operadora_id', 'cnpj']);
                $table->unique('cnpj');
                $table->dropConstrainedForeignId('empresa_operadora_id');
            });
        }

        if (Schema::hasColumn('users', 'empresa_operadora_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('empresa_operadora_id');
            });
        }

        if (Schema::hasColumn('empresas_operadoras', 'ativo')) {
            Schema::table('empresas_operadoras', function (Blueprint $table) {
                $table->dropColumn('ativo');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'gerente', 'operador') NOT NULL DEFAULT 'operador'");
        }
    }

    private function addSuperAdminRole(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'gerente', 'operador') NOT NULL DEFAULT 'operador'");
        }
    }

    private function criarOperadoraPadrao(): int
    {
        $operadoraId = DB::table('empresas_operadoras')->value('id');

        if ($operadoraId) {
            DB::table('empresas_operadoras')
                ->where('id', $operadoraId)
                ->update(['ativo' => true, 'updated_at' => now()]);

            return (int) $operadoraId;
        }

        return (int) DB::table('empresas_operadoras')->insertGetId([
            'razao_social' => 'Escritório Padrão',
            'nome_fantasia' => 'IntegraExpert',
            'cnpj' => '00.000.000/0001-00',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function associarDadosLegados(int $operadoraId): void
    {
        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'empresa_operadora_id')) {
                DB::table($tableName)
                    ->whereNull('empresa_operadora_id')
                    ->update(['empresa_operadora_id' => $operadoraId]);
            }
        }

        if (Schema::hasColumn('users', 'empresa_operadora_id')) {
            DB::table('users')
                ->whereNull('empresa_operadora_id')
                ->where('role', '!=', 'super_admin')
                ->update(['empresa_operadora_id' => $operadoraId]);
        }
    }
};
