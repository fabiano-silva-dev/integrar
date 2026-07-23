<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\ImportacaoEmpresa;
use App\Models\User;
use App\Rules\CnpjValido;
use App\Services\AutomacaoFiscal\ImportacaoEmpresasService;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantAutomacaoFiscalImportacaoEmpresasTest extends TestCase
{
    use DatabaseTransactions;

    public function test_importacao_cria_empresa_e_vinculos_sem_segredos(): void
    {
        $this->seed(PortaisIntegracaoSeeder::class);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'role' => 'admin',
        ]);

        $cnpj = '04.252.011/0001-10';
        $this->assertTrue(CnpjValido::isValid($cnpj));

        $service = app(ImportacaoEmpresasService::class);
        $mapeamento = [
            'razao_social' => 'razao',
            'nome_fantasia' => 'fantasia',
            'cnpj' => 'cnpj',
            'uf' => 'uf',
            'habilitar_ecac_rs' => 'ecac',
            'habilitar_nfe' => 'nfe',
            'habilitar_nfce' => 'nfce',
            'habilitar_nfse_nacional' => 'nfse',
            'habilitar_nfse' => 'nfse_emit',
            'inscricao_estadual' => '',
            'inscricao_municipal' => '',
            'municipio' => '',
            'codigo_municipio_ibge' => '',
            'codigo_sistema' => '',
            'codigo_conta_banco' => '',
            'ativo' => '',
            'agenda_padrao' => '',
        ];

        $linhas = [[
            'razao' => 'Empresa Importada LTDA',
            'fantasia' => 'Empresa Importada',
            'cnpj' => $cnpj,
            'uf' => 'RS',
            'ecac' => 'sim',
            'nfe' => 'sim',
            'nfce' => 'nao',
            'nfse' => 'sim',
            'nfse_emit' => 'sim',
        ]];

        $previa = $service->gerarPrevia($linhas, $mapeamento, $operadora->id);
        $this->assertEquals(1, $previa['resumo']['criar']);

        $importacao = ImportacaoEmpresa::create([
            'empresa_operadora_id' => $operadora->id,
            'user_id' => $user->id,
            'nome_arquivo' => 'empresas.csv',
            'status' => 'processando',
        ]);

        $service->gravar($importacao, $previa['itens'], $mapeamento);

        $empresa = Empresa::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $operadora->id)
            ->where('cnpj', CnpjValido::format($cnpj))
            ->first();

        $this->assertNotNull($empresa);
        $this->assertEquals('Empresa Importada', $empresa->nome);
        $this->assertTrue($empresa->integracoes()->where('ativo', true)->exists());
        $this->assertDatabaseMissing('empresa_integracao_credenciais', [
            'empresa_operadora_id' => $operadora->id,
        ]);
    }
}
