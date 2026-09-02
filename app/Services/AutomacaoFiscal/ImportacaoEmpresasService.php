<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\AgendaAutomacao;
use App\Models\Empresa;
use App\Models\ImportacaoEmpresa;
use App\Models\ImportacaoEmpresaItem;
use App\Rules\CnpjValido;
use App\Services\Importacao\LeitorArquivoTabularService;
use Illuminate\Support\Facades\DB;

class ImportacaoEmpresasService
{
    public const CAMPOS = [
        'razao_social' => 'Razão social',
        'nome_fantasia' => 'Nome fantasia',
        'cnpj' => 'CNPJ',
        'inscricao_estadual' => 'Inscrição estadual',
        'inscricao_municipal' => 'Inscrição municipal',
        'uf' => 'UF',
        'municipio' => 'Município',
        'codigo_municipio_ibge' => 'Código IBGE',
        'codigo_sistema' => 'Código no sistema',
        'codigo_conta_banco' => 'Conta bancária contábil',
        'ativo' => 'Ativo',
        'habilitar_ecac_rs' => 'Habilitar e-CAC RS',
        'habilitar_nfe' => 'Habilitar NF-e',
        'habilitar_nfce' => 'Habilitar NFC-e',
        'habilitar_nfse_nacional' => 'Habilitar Portal Nacional',
        'habilitar_nfse' => 'Habilitar NFS-e',
        'agenda_padrao' => 'Nome da agenda padrão',
    ];

    public const SINONIMOS = [
        'razao_social' => ['razao social', 'razão social', 'razao_social'],
        'nome_fantasia' => ['nome fantasia', 'nome_fantasia', 'nome', 'fantasia'],
        'cnpj' => ['cnpj', 'cnpj/cpf'],
        'inscricao_estadual' => ['inscricao estadual', 'inscrição estadual', 'ie', 'inscricao_estadual'],
        'inscricao_municipal' => ['inscricao municipal', 'inscrição municipal', 'im', 'inscricao_municipal'],
        'uf' => ['uf', 'estado'],
        'municipio' => ['municipio', 'município', 'cidade'],
        'codigo_municipio_ibge' => ['codigo ibge', 'código ibge', 'ibge', 'codigo_municipio_ibge'],
        'codigo_sistema' => ['codigo sistema', 'código sistema', 'codigo_sistema', 'codigo contabil'],
        'codigo_conta_banco' => ['conta banco', 'conta bancaria', 'codigo_conta_banco', 'conta contábil'],
        'ativo' => ['ativo', 'ativa', 'status'],
        'habilitar_ecac_rs' => ['habilitar ecac', 'ecac rs', 'habilitar_ecac_rs'],
        'habilitar_nfe' => ['habilitar nfe', 'nfe', 'habilitar_nfe'],
        'habilitar_nfce' => ['habilitar nfce', 'nfce', 'habilitar_nfce'],
        'habilitar_nfse_nacional' => ['habilitar portal nacional', 'portal nacional', 'habilitar_nfse_nacional'],
        'habilitar_nfse' => ['habilitar nfse', 'nfse', 'habilitar_nfse'],
        'agenda_padrao' => ['agenda', 'agenda padrao', 'agenda padrão', 'agenda_padrao'],
    ];

    public function detectarMapeamento(array $colunas): array
    {
        $mapa = array_fill_keys(array_keys(self::CAMPOS), '');

        foreach ($colunas as $coluna) {
            $normalizada = $this->normalizarCabecalho((string) $coluna);
            foreach (self::SINONIMOS as $campo => $sinonimos) {
                if ($mapa[$campo] !== '') {
                    continue;
                }
                foreach ($sinonimos as $sinonimo) {
                    if ($normalizada === $this->normalizarCabecalho($sinonimo)) {
                        $mapa[$campo] = $coluna;
                        break 2;
                    }
                }
            }
        }

        return $mapa;
    }

    /**
     * @param  array<string, string>  $mapeamento
     * @return array{itens: array<int, array<string, mixed>>, resumo: array<string, int>}
     */
    public function gerarPrevia(array $linhas, array $mapeamento, int $operadoraId): array
    {
        $itens = [];
        $resumo = [
            'total' => 0,
            'criar' => 0,
            'atualizar' => 0,
            'erro' => 0,
        ];

        foreach ($linhas as $indice => $linha) {
            $numeroLinha = $indice + 1;
            $dados = $this->extrairLinha($linha, $mapeamento);
            $validacao = $this->validarLinha($dados, $operadoraId);

            $acao = $validacao['ok']
                ? ($validacao['empresa_existente'] ? 'atualizar' : 'criar')
                : 'erro';

            $itens[] = [
                'numero_linha' => $numeroLinha,
                'dados_brutos' => $linha,
                'dados_normalizados' => $dados,
                'status' => $acao === 'erro' ? 'erro' : 'valido',
                'acao' => $acao,
                'mensagem_erro' => $validacao['erro'],
                'empresa_id' => $validacao['empresa_id'],
            ];

            $resumo['total']++;
            if ($acao === 'criar') {
                $resumo['criar']++;
            } elseif ($acao === 'atualizar') {
                $resumo['atualizar']++;
            } else {
                $resumo['erro']++;
            }
        }

        return compact('itens', 'resumo');
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     */
    public function gravar(ImportacaoEmpresa $importacao, array $itens, array $mapeamento): ImportacaoEmpresa
    {
        $operadoraId = (int) $importacao->empresa_operadora_id;
        $integracaoService = app(EmpresaIntegracaoService::class);

        return DB::transaction(function () use ($importacao, $itens, $mapeamento, $operadoraId, $integracaoService) {
            $gravadas = 0;
            $erros = 0;

            foreach ($itens as $item) {
                $registro = ImportacaoEmpresaItem::create([
                    'empresa_operadora_id' => $operadoraId,
                    'importacao_empresa_id' => $importacao->id,
                    'numero_linha' => $item['numero_linha'],
                    'dados_brutos' => $item['dados_brutos'],
                    'dados_normalizados' => $item['dados_normalizados'],
                    'status' => 'pendente',
                    'mensagem_erro' => null,
                ]);

                if (($item['status'] ?? '') === 'erro') {
                    $registro->update([
                        'status' => 'erro',
                        'mensagem_erro' => $item['mensagem_erro'] ?? 'Linha inválida',
                    ]);
                    $erros++;
                    continue;
                }

                $dados = $item['dados_normalizados'];
                $cnpj = CnpjValido::format($dados['cnpj']);
                $nome = $dados['nome_fantasia'] ?: ($dados['razao_social'] ?: $cnpj);

                $empresa = Empresa::withoutGlobalScope('operadora')
                    ->where('empresa_operadora_id', $operadoraId)
                    ->where('cnpj', $cnpj)
                    ->first();

                $payload = [
                    'empresa_operadora_id' => $operadoraId,
                    'nome' => $nome,
                    'razao_social' => $dados['razao_social'] ?: $nome,
                    'nome_fantasia' => $dados['nome_fantasia'] ?: $nome,
                    'cnpj' => $cnpj,
                    'inscricao_estadual' => $dados['inscricao_estadual'] ?: null,
                    'inscricao_municipal' => $dados['inscricao_municipal'] ?: null,
                    'uf' => $dados['uf'] ?: null,
                    'municipio' => $dados['municipio'] ?: null,
                    'codigo_municipio_ibge' => $dados['codigo_municipio_ibge'] ?: null,
                    'codigo_sistema' => $dados['codigo_sistema'] ?: null,
                    'codigo_conta_banco' => $dados['codigo_conta_banco'] ?: null,
                    'ativo' => $dados['ativo'] ?? true,
                ];

                if ($empresa) {
                    $atualizacao = array_filter(
                        $payload,
                        fn ($valor, $chave) => $chave === 'empresa_operadora_id'
                            || $chave === 'cnpj'
                            || $chave === 'ativo'
                            || ($valor !== null && $valor !== ''),
                        ARRAY_FILTER_USE_BOTH
                    );
                    $empresa->update($atualizacao);
                } else {
                    $empresa = Empresa::create($payload);
                }

                $agendaId = null;
                if (!empty($dados['agenda_padrao'])) {
                    $agenda = AgendaAutomacao::query()
                        ->where('empresa_operadora_id', $operadoraId)
                        ->where('nome', $dados['agenda_padrao'])
                        ->first();
                    $agendaId = $agenda?->id;
                }

                $integracaoService->sincronizar($empresa, $this->montarPortaisDaLinha($dados, $agendaId));

                $registro->update([
                    'status' => 'gravado',
                    'empresa_id' => $empresa->id,
                ]);
                $gravadas++;
            }

            $importacao->update([
                'status' => 'concluida',
                'mapeamento_colunas' => $mapeamento,
                'total_linhas' => count($itens),
                'linhas_validas' => $gravadas,
                'linhas_com_erro' => $erros,
                'linhas_gravadas' => $gravadas,
                'mensagem' => "{$gravadas} empresa(s) gravada(s), {$erros} com erro.",
            ]);

            return $importacao->fresh();
        });
    }

    public function lerArquivo(string $caminho, string $extensao, string $delimitador = ';'): array
    {
        $leitor = new LeitorArquivoTabularService();

        return $leitor->ler($caminho, $extensao, 1, $delimitador, true);
    }

    /**
     * @param  array<string, mixed>  $linha
     * @param  array<string, string>  $mapeamento
     * @return array<string, mixed>
     */
    private function extrairLinha(array $linha, array $mapeamento): array
    {
        $dados = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $coluna = $mapeamento[$campo] ?? '';
            $valor = $coluna !== '' ? ($linha[$coluna] ?? null) : null;
            if (is_string($valor)) {
                $valor = trim($valor);
            }
            $dados[$campo] = $valor;
        }

        $dados['cnpj'] = CnpjValido::digits((string) ($dados['cnpj'] ?? ''));
        $dados['uf'] = $dados['uf'] ? strtoupper(substr((string) $dados['uf'], 0, 2)) : null;
        $dados['ativo'] = $this->paraBoolean($dados['ativo'] ?? true, true);
        foreach (['habilitar_ecac_rs', 'habilitar_nfe', 'habilitar_nfce', 'habilitar_nfse_nacional', 'habilitar_nfse'] as $flag) {
            $dados[$flag] = $this->paraBoolean($dados[$flag] ?? false, false);
        }

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{ok: bool, erro: ?string, empresa_existente: bool, empresa_id: ?int}
     */
    private function validarLinha(array $dados, int $operadoraId): array
    {
        if (strlen((string) $dados['cnpj']) !== 14 || !CnpjValido::isValid((string) $dados['cnpj'])) {
            return [
                'ok' => false,
                'erro' => 'CNPJ inválido.',
                'empresa_existente' => false,
                'empresa_id' => null,
            ];
        }

        if (empty($dados['razao_social']) && empty($dados['nome_fantasia'])) {
            return [
                'ok' => false,
                'erro' => 'Informe razão social ou nome fantasia.',
                'empresa_existente' => false,
                'empresa_id' => null,
            ];
        }

        $cnpjFormatado = CnpjValido::format($dados['cnpj']);
        $existente = Empresa::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $operadoraId)
            ->where('cnpj', $cnpjFormatado)
            ->first();

        return [
            'ok' => true,
            'erro' => null,
            'empresa_existente' => $existente !== null,
            'empresa_id' => $existente?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<int, array<string, mixed>>
     */
    private function montarPortaisDaLinha(array $dados, ?int $agendaId): array
    {
        $ecacAtivo = (bool) ($dados['habilitar_ecac_rs'] || $dados['habilitar_nfe'] || $dados['habilitar_nfce']);
        $nfseAtivo = (bool) ($dados['habilitar_nfse_nacional'] || $dados['habilitar_nfse']);

        return [
            [
                'portal_codigo' => 'ecac_rs',
                'ativo' => $ecacAtivo,
                'recursos' => [
                    'nfe_emitidas' => [
                        'ativo' => (bool) ($dados['habilitar_nfe'] || $dados['habilitar_ecac_rs']),
                        'agenda_automacao_id' => $agendaId,
                    ],
                    'nfce_emitidas' => [
                        'ativo' => (bool) $dados['habilitar_nfce'],
                        'agenda_automacao_id' => $agendaId,
                    ],
                ],
            ],
            [
                'portal_codigo' => 'nfse_nacional',
                'ativo' => $nfseAtivo,
                'recursos' => [
                    'nfse_emitidas' => [
                        'ativo' => (bool) ($dados['habilitar_nfse'] || $dados['habilitar_nfse_nacional']),
                        'agenda_automacao_id' => $agendaId,
                    ],
                    'nfse_recebidas' => [
                        'ativo' => (bool) ($dados['habilitar_nfse'] || $dados['habilitar_nfse_nacional']),
                        'agenda_automacao_id' => $agendaId,
                    ],
                ],
            ],
        ];
    }

    private function paraBoolean(mixed $valor, bool $padrao): bool
    {
        if ($valor === null || $valor === '') {
            return $padrao;
        }

        if (is_bool($valor)) {
            return $valor;
        }

        $normalizado = strtolower(trim((string) $valor));

        return in_array($normalizado, ['1', 'true', 'sim', 's', 'yes', 'ativo', 'ativa'], true);
    }

    private function normalizarCabecalho(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ];
        $valor = strtr($valor, $map);

        return preg_replace('/\s+/', ' ', $valor) ?? $valor;
    }
}
