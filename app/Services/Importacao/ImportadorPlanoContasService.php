<?php

namespace App\Services\Importacao;

use App\Models\ImportacaoPlanoConta;
use App\Models\PlanoConta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImportadorPlanoContasService
{
    public const ESTRATEGIAS = [
        'adicionar_atualizar' => 'Adicionar e atualizar',
        'somente_adicionar' => 'Somente adicionar',
        'substituir' => 'Substituir plano (inativar ausentes)',
        'validar_apenas' => 'Validar apenas (não grava)',
    ];

    public const CAMPOS = [
        'codigo' => 'Código',
        'classificacao' => 'Classificação',
        'codigo_reduzido' => 'Código reduzido',
        'descricao' => 'Nome',
        'tipo' => 'T (sintética)',
        'natureza' => 'Natureza (D/C)',
        'nivel' => 'Grau',
        'codigo_pai' => 'Código pai',
        'aceita_lancamento' => 'Aceita lançamento',
    ];

    public const SINONIMOS = [
        'codigo' => ['codigo', 'código'],
        'classificacao' => ['classificacao', 'classificação'],
        'codigo_reduzido' => ['reduzida', 'reduzido', 'codigo reduzido', 'código reduzido'],
        'descricao' => ['descricao', 'descrição', 'nome', 'nome da conta'],
        'tipo' => ['tipo', 'analitica/sintetica', 'analítica/sintética'],
        'natureza' => ['natureza', 'd/c', 'dc'],
        'nivel' => ['nivel', 'nível', 'grau'],
        'codigo_pai' => ['codigo pai', 'código pai', 'conta pai', 'pai'],
        'aceita_lancamento' => ['aceita lancamento', 'aceita lançamento', 'lancamento', 'lançamento'],
    ];

    /**
     * @param  list<string>  $colunasArquivo
     * @return array<string, string>
     */
    public function sugerirMapeamento(array $colunasArquivo): array
    {
        $mapeamento = [];

        foreach (self::CAMPOS as $campo => $label) {
            $mapeamento[$campo] = $this->encontrarColuna($colunasArquivo, self::SINONIMOS[$campo] ?? []);
        }

        return $mapeamento;
    }

    /**
     * @param  list<array<string, string>>  $linhas
     * @param  array<string, string>  $mapeamento
     */
    public function analisar(array $linhas, array $mapeamento, int $empresaId, string $estrategia): array
    {
        $this->validarMapeamentoObrigatorio($mapeamento);

        $existentes = PlanoConta::where('empresa_id', $empresaId)
            ->get()
            ->keyBy(fn (PlanoConta $c) => PlanoConta::normalizarCodigo($c->codigo));

        $codigosArquivo = [];
        $contas = [];
        $erros = [];
        $ignoradas = 0;

        foreach ($linhas as $indice => $linha) {
            $numeroLinha = $indice + 2;
            $conta = $this->extrairConta($linha, $mapeamento, $numeroLinha);

            if (!empty($conta['erros'])) {
                foreach ($conta['erros'] as $erro) {
                    $erros[] = ['linha' => $numeroLinha, 'mensagem' => $erro];
                }
                continue;
            }

            $codigo = $conta['dados']['codigo'];
            if (isset($codigosArquivo[$codigo])) {
                $erros[] = [
                    'linha' => $numeroLinha,
                    'mensagem' => "Código duplicado no arquivo: {$codigo}",
                ];
                continue;
            }

            $codigosArquivo[$codigo] = true;
            $existe = $existentes->has($codigo);

            if ($estrategia === 'somente_adicionar' && $existe) {
                $ignoradas++;
                continue;
            }

            $conta['dados']['_existe'] = $existe;
            $conta['dados']['_linha'] = $numeroLinha;
            $contas[] = $conta['dados'];
        }

        $this->resolverPaisPorClassificacao($contas, $existentes);

        $codigosValidos = array_keys($codigosArquivo);
        $paisInexistentes = $this->validarPais($contas, $codigosValidos, $existentes);

        foreach ($paisInexistentes as $aviso) {
            $erros[] = $aviso;
        }

        $novas = 0;
        $atualizadas = 0;
        foreach ($contas as $conta) {
            if ($conta['_existe']) {
                $atualizadas++;
            } else {
                $novas++;
            }
        }

        $inativadas = 0;
        if ($estrategia === 'substituir') {
            $inativadas = PlanoConta::where('empresa_id', $empresaId)
                ->where('ativo', true)
                ->whereNotIn('codigo', $codigosValidos)
                ->count();
        }

        return [
            'total_linhas' => count($linhas),
            'contas_validas' => count($contas),
            'contas_novas' => $novas,
            'contas_atualizadas' => $atualizadas,
            'contas_ignoradas' => $ignoradas,
            'contas_inativadas' => $inativadas,
            'linhas_erro' => count($erros),
            'erros' => $erros,
            'contas' => $contas,
            'amostra' => array_slice($contas, 0, 20),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $contas
     */
    public function persistir(
        array $contas,
        int $empresaId,
        string $estrategia,
        string $arquivoOriginal,
        string $formato,
        array $erros = []
    ): ImportacaoPlanoConta {
        if ($estrategia === 'validar_apenas') {
            return ImportacaoPlanoConta::create([
                'empresa_id' => $empresaId,
                'user_id' => Auth::id(),
                'arquivo_original' => $arquivoOriginal,
                'formato' => $formato,
                'estrategia' => $estrategia,
                'total_linhas' => count($contas),
                'contas_novas' => collect($contas)->where('_existe', false)->count(),
                'contas_atualizadas' => collect($contas)->where('_existe', true)->count(),
                'linhas_erro' => count($erros),
                'relatorio_erros' => $erros,
                'status' => 'validacao',
            ]);
        }

        $novas = 0;
        $atualizadas = 0;
        $inativadas = 0;
        $codigosImportados = [];

        DB::transaction(function () use ($contas, $empresaId, $estrategia, &$novas, &$atualizadas, &$inativadas, &$codigosImportados) {
            foreach (array_chunk($contas, 100) as $lote) {
                foreach ($lote as $conta) {
                    $codigo = $conta['codigo'];
                    $codigosImportados[] = $codigo;

                    $payload = [
                        'codigo' => $codigo,
                        'classificacao' => $conta['classificacao'] ?? null,
                        'codigo_reduzido' => $conta['codigo_reduzido'] ?: null,
                        'descricao' => $conta['descricao'],
                        'tipo' => $conta['tipo'],
                        'natureza' => $conta['natureza'],
                        'nivel' => $conta['nivel'],
                        'codigo_pai' => $conta['codigo_pai'],
                        'aceita_lancamento' => $conta['aceita_lancamento'],
                        'ativo' => true,
                        'empresa_id' => $empresaId,
                    ];

                    $existente = PlanoConta::where('empresa_id', $empresaId)
                        ->where('codigo', $codigo)
                        ->first();

                    if ($existente) {
                        $existente->update($payload);
                        $atualizadas++;
                    } else {
                        PlanoConta::create($payload);
                        $novas++;
                    }
                }
            }

            if ($estrategia === 'substituir') {
                $inativadas = PlanoConta::where('empresa_id', $empresaId)
                    ->where('ativo', true)
                    ->whereNotIn('codigo', $codigosImportados)
                    ->update(['ativo' => false]);
            }
        });

        return ImportacaoPlanoConta::create([
            'empresa_id' => $empresaId,
            'user_id' => Auth::id(),
            'arquivo_original' => $arquivoOriginal,
            'formato' => $formato,
            'estrategia' => $estrategia,
            'total_linhas' => count($contas),
            'contas_novas' => $novas,
            'contas_atualizadas' => $atualizadas,
            'contas_inativadas' => $estrategia === 'substituir' ? $inativadas : 0,
            'linhas_erro' => count($erros),
            'relatorio_erros' => $erros ?: null,
            'status' => 'concluida',
        ]);
    }

    public function conteudoModeloCsv(): string
    {
        $linhas = [
            'codigo;codigo_reduzido;descricao;tipo;natureza;nivel;codigo_pai;aceita_lancamento',
            '1;;ATIVO;sintetica;devedora;1;;nao',
            '1.1;;ATIVO CIRCULANTE;sintetica;devedora;2;1;nao',
            '1.1.01.001;101;CAIXA GERAL;analitica;devedora;4;1.1.01;sim',
        ];

        return implode("\n", $linhas) . "\n";
    }

    /**
     * @param  array<string, string>  $mapeamento
     */
    private function validarMapeamentoObrigatorio(array $mapeamento): void
    {
        if (empty($mapeamento['codigo'])) {
            throw new \InvalidArgumentException('Mapeie a coluna do código da conta.');
        }

        if (empty($mapeamento['descricao'])) {
            throw new \InvalidArgumentException('Mapeie a coluna da descrição.');
        }
    }

    /**
     * @param  list<string>  $colunas
     * @param  list<string>  $sinonimos
     */
    private function encontrarColuna(array $colunas, array $sinonimos): string
    {
        foreach ($colunas as $coluna) {
            $normColuna = $this->normalizarTexto($coluna);
            foreach ($sinonimos as $sinonimo) {
                if ($normColuna === $this->normalizarTexto($sinonimo)) {
                    return $coluna;
                }
            }
        }

        foreach ($colunas as $coluna) {
            $normColuna = $this->normalizarTexto($coluna);
            foreach ($sinonimos as $sinonimo) {
                $normSin = $this->normalizarTexto($sinonimo);
                if ($normSin !== '' && str_contains($normColuna, $normSin)) {
                    return $coluna;
                }
            }
        }

        return '';
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = str_replace(['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'], ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'], $texto);

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
    }

    /**
     * @param  array<string, string>  $linha
     * @param  array<string, string>  $mapeamento
     * @return array{dados: array<string, mixed>, erros: list<string>}
     */
    private function extrairConta(array $linha, array $mapeamento, int $numeroLinha): array
    {
        $erros = [];
        $codigo = PlanoConta::normalizarCodigo($this->valorMapeado($linha, $mapeamento, 'codigo'));
        $descricao = trim($this->valorMapeado($linha, $mapeamento, 'descricao'));

        if ($codigo === '') {
            $erros[] = 'Conta sem código';
        }

        if ($descricao === '') {
            $erros[] = 'Conta sem descrição';
        }

        if ($erros !== []) {
            return ['dados' => [], 'erros' => $erros];
        }

        $tipo = PlanoConta::normalizarTipo($this->valorMapeado($linha, $mapeamento, 'tipo'));
        $nivelRaw = $this->valorMapeado($linha, $mapeamento, 'nivel');
        $nivel = $nivelRaw !== '' ? (int) $nivelRaw : PlanoConta::inferirNivel($codigo);
        $codigoPai = PlanoConta::normalizarCodigo($this->valorMapeado($linha, $mapeamento, 'codigo_pai'));
        $classificacao = PlanoConta::normalizarCodigo($this->valorMapeado($linha, $mapeamento, 'classificacao'));
        if ($codigoPai === '' && $classificacao === '') {
            $codigoPai = PlanoConta::inferirCodigoPai($codigo);
        }

        return [
            'dados' => [
                'codigo' => $codigo,
                'classificacao' => $classificacao !== '' ? $classificacao : null,
                'codigo_reduzido' => PlanoConta::normalizarCodigo($this->valorMapeado($linha, $mapeamento, 'codigo_reduzido')),
                'descricao' => $descricao,
                'tipo' => $tipo,
                'natureza' => PlanoConta::normalizarNatureza($this->valorMapeado($linha, $mapeamento, 'natureza')),
                'nivel' => $nivel > 0 ? $nivel : 1,
                'codigo_pai' => $codigoPai,
                'aceita_lancamento' => PlanoConta::normalizarAceitaLancamento(
                    $this->valorMapeado($linha, $mapeamento, 'aceita_lancamento'),
                    $tipo
                ),
            ],
            'erros' => [],
        ];
    }

    /**
     * @param  array<string, string>  $linha
     * @param  array<string, string>  $mapeamento
     */
    private function valorMapeado(array $linha, array $mapeamento, string $campo): string
    {
        $coluna = $mapeamento[$campo] ?? '';
        if ($coluna === '') {
            return '';
        }

        return (string) ($linha[$coluna] ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $contas
     * @param  list<string>  $codigosArquivo
     */
    private function validarPais(array $contas, array $codigosArquivo, $existentes): array
    {
        $avisos = [];
        $codigosConhecidos = array_fill_keys($codigosArquivo, true);
        foreach ($existentes as $codigo => $conta) {
            $codigosConhecidos[$codigo] = true;
        }

        foreach ($contas as $conta) {
            $pai = $conta['codigo_pai'] ?? null;
            if ($pai && !isset($codigosConhecidos[$pai])) {
                $avisos[] = [
                    'linha' => $conta['_linha'] ?? 0,
                    'mensagem' => "Conta-pai inexistente: {$pai} (conta {$conta['codigo']})",
                ];
            }
        }

        return $avisos;
    }

    /**
     * No Domínio, a classificação define a hierarquia; o código da conta-pai é o Código da linha pai.
     *
     * @param  list<array<string, mixed>>  $contas
     */
    private function resolverPaisPorClassificacao(array &$contas, $existentes): void
    {
        $classificacaoParaCodigo = [];

        foreach ($existentes as $codigo => $conta) {
            if (!empty($conta->classificacao)) {
                $classificacaoParaCodigo[$conta->classificacao] = $codigo;
            }
        }

        foreach ($contas as $conta) {
            $classificacao = $conta['classificacao'] ?? null;
            if ($classificacao) {
                $classificacaoParaCodigo[$classificacao] = $conta['codigo'];
            }
        }

        foreach ($contas as &$conta) {
            $classificacao = $conta['classificacao'] ?? null;
            if (!$classificacao) {
                continue;
            }

            $classificacaoPai = PlanoConta::inferirCodigoPai($classificacao);
            if ($classificacaoPai && isset($classificacaoParaCodigo[$classificacaoPai])) {
                $conta['codigo_pai'] = $classificacaoParaCodigo[$classificacaoPai];
            }
        }
        unset($conta);
    }
}
