<?php

namespace Database\Seeders;

use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use Illuminate\Database\Seeder;

class PortaisIntegracaoSeeder extends Seeder
{
    public function run(): void
    {
        $ecac = PortalIntegracao::updateOrCreate(
            ['codigo' => 'ecac_rs'],
            [
                'nome' => 'e-CAC RS (Receita Estadual)',
                'driver' => 'ecac_rs',
                'ativo' => true,
                'modos_autenticacao' => ['certificado_a1'],
                'configuracoes_publicas' => [
                    'entry_url' => 'https://www.sefaz.rs.gov.br/Login/LoginCertACRS.aspx?codTpLogin=1',
                    'allowed_host_suffixes' => ['rs.gov.br'],
                ],
            ]
        );

        $this->syncRecursos($ecac, [
            [
                'codigo' => 'nfe_emitidas',
                'nome' => 'Extrato NF-e/NFC-e',
                'descricao' => 'Extrato NF-e/NFC-e no e-CAC RS (modelo escolhido nos parâmetros).',
                'parametros_schema' => $this->schemaExtratoNfeNfce('nfe'),
                'ativo' => true,
            ],
            [
                'codigo' => 'nfce_emitidas',
                'nome' => 'NFC-e emitidas (legado)',
                'descricao' => 'Recurso legado — use Extrato NF-e/NFC-e com modelo NFC-e.',
                'parametros_schema' => $this->schemaExtratoNfeNfce('nfce'),
                'ativo' => false,
            ],
            [
                'codigo' => 'validar_acesso',
                'nome' => 'Validar acesso',
                'descricao' => 'Testa autenticação por certificado no e-CAC RS.',
                'parametros_schema' => null,
                'ativo' => true,
            ],
        ]);

        $nfse = PortalIntegracao::updateOrCreate(
            ['codigo' => 'nfse_nacional'],
            [
                'nome' => 'Portal Nacional da NFS-e',
                'driver' => 'nfse_nacional',
                'ativo' => true,
                'modos_autenticacao' => ['certificado_a1'],
                'configuracoes_publicas' => [
                    'entry_url' => 'https://www.nfse.gov.br/EmissorNacional/Login',
                    'allowed_host_suffixes' => ['nfse.gov.br'],
                ],
            ]
        );

        $this->syncRecursos($nfse, [
            [
                'codigo' => 'nfse_emitidas',
                'nome' => 'NFS-e emitidas',
                'descricao' => 'Listagem de NFS-e emitidas no Emissor Nacional (filtro por período; chaves no HTML).',
                'parametros_schema' => $this->schemaExtratoNfse(),
            ],
            [
                'codigo' => 'nfse_recebidas',
                'nome' => 'NFS-e recebidas',
                'descricao' => 'Listagem de NFS-e recebidas no Emissor Nacional (filtro por período; chaves no HTML).',
                'parametros_schema' => $this->schemaExtratoNfse(),
            ],
            [
                'codigo' => 'validar_acesso',
                'nome' => 'Validar acesso',
                'descricao' => 'Testa autenticação por certificado no Portal Nacional da NFS-e.',
                'parametros_schema' => null,
            ],
        ]);
    }

    /**
     * Parâmetros alinhados à query do portal:
     * /EmissorNacional/Notas/{Emitidas|Recebidas}?executar=1&busca=&datainicio=&datafim=
     *
     * @return array<string, mixed>
     */
    private function schemaExtratoNfse(): array
    {
        return [
            'periodo_inicial' => [
                'type' => 'date',
                'required' => true,
                'label' => 'Data Inicial',
                'hint' => '(DD/MM/AAAA) · Máx. 30 dias',
            ],
            'periodo_final' => [
                'type' => 'date',
                'required' => true,
                'label' => 'Data Final',
                'hint' => '(DD/MM/AAAA) · Máx. 30 dias',
            ],
            'busca' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Pesquisar pessoa física ou jurídica',
                'default' => '',
            ],
        ];
    }

    /**
     * @param  array<int, array{codigo: string, nome: string, descricao: string, parametros_schema: mixed, ativo?: bool}>  $recursos
     */
    private function syncRecursos(PortalIntegracao $portal, array $recursos): void
    {
        foreach ($recursos as $recurso) {
            PortalRecurso::updateOrCreate(
                [
                    'portal_integracao_id' => $portal->id,
                    'codigo' => $recurso['codigo'],
                ],
                [
                    'nome' => $recurso['nome'],
                    'descricao' => $recurso['descricao'],
                    'ativo' => $recurso['ativo'] ?? true,
                    'parametros_schema' => $recurso['parametros_schema'],
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaExtratoNfeNfce(string $modeloPadrao): array
    {
        return [
            'ie' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Inscrição Estadual',
            ],
            'cnpj' => [
                'type' => 'string',
                'required' => false,
                'label' => 'CNPJ',
            ],
            'modelo' => [
                'type' => 'enum',
                'widget' => 'checkboxes',
                'values' => ['nfe', 'nfce', 'ambos'],
                'options' => [
                    ['value' => 'nfe', 'label' => 'NF-e'],
                    ['value' => 'nfce', 'label' => 'NFC-e'],
                    ['value' => 'ambos', 'label' => 'NF-e e NFC-e'],
                ],
                'default' => $modeloPadrao,
                'required' => true,
                'label' => 'Modelo',
            ],
            'totalizado_por_mes' => [
                'type' => 'boolean',
                'default' => false,
                'label' => 'Totalizado por mês',
            ],
            'periodo_inicial' => [
                'type' => 'date',
                'required' => true,
                'label' => 'Período Inicial',
                'hint' => '(DD/MM/AAAA)',
            ],
            'periodo_final' => [
                'type' => 'date',
                'required' => true,
                'label' => 'Período Final',
                'hint' => '(DD/MM/AAAA) · Máx. 31 dias',
            ],
            'operacao' => [
                'type' => 'enum',
                'widget' => 'radio',
                'values' => [
                    'saida-consulente',
                    'saida-terceiros',
                    'entrada-consulente',
                    'entrada-terceiros',
                ],
                'options' => [
                    [
                        'value' => 'saida-consulente',
                        'label' => 'Exibir as NF-e\'s/NFC-e\'s de Saída emitidas pelo consulente (ou seja, o emitente da NF-e/NFC-e é o consulente)',
                    ],
                    [
                        'value' => 'saida-terceiros',
                        'label' => 'Exibir as NF-e\'s/NFC-e\'s de Saída emitidas por terceiros (ou seja, o destinatário da NF-e/NFC-e é o consulente)',
                    ],
                    [
                        'value' => 'entrada-consulente',
                        'label' => 'Exibir as NF-e\'s de Entrada emitidas pelo consulente (ou seja, o emitente da NF-e é o consulente)',
                    ],
                    [
                        'value' => 'entrada-terceiros',
                        'label' => 'Exibir as NF-e\'s de Entrada emitidas por terceiros (ou seja, o remetente da NF-e é o consulente)',
                    ],
                ],
                'required' => true,
                'label' => 'Operação',
            ],
            'excluir_venda_fora_estabelecimento' => [
                'type' => 'boolean',
                'default' => false,
                'label' => 'Sem as NF-e\'s/NFC-e\'s exclusivamente de venda fora do estabelecimento (CFOP: 5103, 5104, 6103 e 6104)',
                'depends_on' => ['operacao' => 'saida-consulente'],
            ],
            'situacao_normal' => [
                'type' => 'boolean',
                'default' => true,
                'label' => 'Normal',
            ],
            'situacao_cancelada' => [
                'type' => 'boolean',
                'default' => false,
                'label' => 'Cancelada',
            ],
        ];
    }
}
