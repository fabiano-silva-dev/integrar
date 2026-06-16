<?php

namespace App\Services\Importacao;

use App\Models\RegraAmarracaoDescricao;
use Illuminate\Support\Facades\DB;

class ImportadorRegrasAmarracaoService
{
    public const ESTRATEGIAS = [
        'adicionar_atualizar' => 'Adicionar e atualizar',
        'somente_adicionar' => 'Somente adicionar novas',
        'substituir_layout' => 'Substituir todas do layout no arquivo',
        'validar_apenas' => 'Validar apenas (não grava)',
    ];

    public const CAMPOS = [
        'layout' => 'Layout',
        'palavra_chave' => 'Palavra-chave',
        'parte_digitavel' => 'Parte digitável',
        'tipo_busca' => 'Tipo de busca',
        'conta_contrapartida' => 'Conta contra-partida',
        'centro_custo' => 'Centro de custo',
        'prioridade' => 'Prioridade',
        'descricao' => 'Histórico contábil',
        'ativo' => 'Ativo',
    ];

    public const SINONIMOS = [
        'layout' => ['layout', 'layout_avancado'],
        'palavra_chave' => ['palavra_chave', 'palavra chave', 'chave', 'keyword'],
        'parte_digitavel' => ['parte_digitavel', 'parte digitavel', 'parte digitável'],
        'tipo_busca' => ['tipo_busca', 'tipo busca', 'busca'],
        'conta_contrapartida' => ['conta_contrapartida', 'conta contrapartida', 'conta contra-partida', 'contrapartida'],
        'centro_custo' => ['centro_custo', 'centro custo', 'cc'],
        'prioridade' => ['prioridade', 'ordem'],
        'descricao' => ['descricao', 'descrição', 'historico', 'histórico', 'historico contabil'],
        'ativo' => ['ativo', 'status'],
    ];

    public function __construct(
        private readonly ValidadorRegraAmarracaoService $validador = new ValidadorRegraAmarracaoService()
    ) {
    }

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
    public function analisar(
        array $linhas,
        array $mapeamento,
        int $empresaId,
        string $estrategia,
        ?string $layoutPadrao = null
    ): array {
        $this->validarMapeamentoObrigatorio($mapeamento, $layoutPadrao);

        $existentes = RegraAmarracaoDescricao::where('empresa_id', $empresaId)
            ->get()
            ->keyBy(fn (RegraAmarracaoDescricao $r) => $this->chaveRegraModel($r));

        $chavesArquivo = [];
        $regras = [];
        $erros = [];
        $avisos = [];
        $ignoradas = 0;

        foreach ($linhas as $indice => $linha) {
            $numeroLinha = $indice + 2;
            $extraida = $this->extrairRegra($linha, $mapeamento, $layoutPadrao, $numeroLinha);

            if (!empty($extraida['erros'])) {
                foreach ($extraida['erros'] as $erro) {
                    $erros[] = ['linha' => $numeroLinha, 'mensagem' => $erro, 'tipo' => 'erro'];
                }
                continue;
            }

            $dados = $extraida['dados'];
            $chave = $this->chaveRegraArray($dados);

            if (isset($chavesArquivo[$chave])) {
                $erros[] = [
                    'linha' => $numeroLinha,
                    'mensagem' => 'Regra duplicada no arquivo (mesma chave lógica)',
                    'tipo' => 'erro',
                ];
                continue;
            }

            $chavesArquivo[$chave] = true;

            $avisoConta = $this->validador->avisoContaContrapartida($dados['conta_contrapartida'], $empresaId);
            if ($avisoConta) {
                $avisos[] = ['linha' => $numeroLinha, 'mensagem' => $avisoConta, 'tipo' => 'aviso'];
            }

            $existe = $existentes->has($chave);

            if (in_array($estrategia, ['somente_adicionar'], true) && $existe) {
                $ignoradas++;
                continue;
            }

            $dados['_existe'] = $existe;
            $dados['_linha'] = $numeroLinha;
            $regras[] = $dados;
        }

        $layoutsAfetados = array_values(array_unique(array_column($regras, 'layout_avancado')));
        $removidas = 0;
        if ($estrategia === 'substituir_layout' && $layoutsAfetados !== []) {
            $removidas = RegraAmarracaoDescricao::where('empresa_id', $empresaId)
                ->whereIn('layout_avancado', $layoutsAfetados)
                ->count();
        }

        $novas = 0;
        $atualizadas = 0;
        foreach ($regras as $regra) {
            if ($regra['_existe']) {
                $atualizadas++;
            } else {
                $novas++;
            }
        }

        return [
            'total_linhas' => count($linhas),
            'regras_validas' => count($regras),
            'regras_novas' => $novas,
            'regras_atualizadas' => $atualizadas,
            'regras_ignoradas' => $ignoradas,
            'regras_removidas' => $removidas,
            'linhas_erro' => count(array_filter($erros, fn ($e) => ($e['tipo'] ?? '') === 'erro')),
            'linhas_aviso' => count($avisos),
            'erros' => $erros,
            'avisos' => $avisos,
            'regras' => $regras,
            'layouts_afetados' => $layoutsAfetados,
            'amostra' => array_slice($regras, 0, 20),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $regras
     * @return array{novas: int, atualizadas: int, ignoradas: int, removidas: int}
     */
    public function persistir(array $regras, int $empresaId, string $estrategia): array
    {
        if ($estrategia === 'validar_apenas') {
            return [
                'novas' => collect($regras)->where('_existe', false)->count(),
                'atualizadas' => collect($regras)->where('_existe', true)->count(),
                'ignoradas' => 0,
                'removidas' => 0,
            ];
        }

        $novas = 0;
        $atualizadas = 0;
        $ignoradas = 0;
        $removidas = 0;

        DB::transaction(function () use ($regras, $empresaId, $estrategia, &$novas, &$atualizadas, &$ignoradas, &$removidas) {
            if ($estrategia === 'substituir_layout') {
                $layouts = array_values(array_unique(array_column($regras, 'layout_avancado')));
                if ($layouts !== []) {
                    $removidas = RegraAmarracaoDescricao::where('empresa_id', $empresaId)
                        ->whereIn('layout_avancado', $layouts)
                        ->delete();
                }
            }

            $existentes = RegraAmarracaoDescricao::where('empresa_id', $empresaId)
                ->get()
                ->keyBy(fn (RegraAmarracaoDescricao $r) => $this->chaveRegraModel($r));

            foreach ($regras as $regra) {
                $chave = $this->chaveRegraArray($regra);
                $payload = $this->payloadPersistencia($regra, $empresaId);
                $existente = $existentes->get($chave);

                if ($existente) {
                    if ($estrategia === 'somente_adicionar') {
                        $ignoradas++;
                        continue;
                    }

                    $existente->update($payload);
                    $atualizadas++;
                    continue;
                }

                RegraAmarracaoDescricao::create($payload);
                $novas++;
            }
        });

        return [
            'novas' => $novas,
            'atualizadas' => $atualizadas,
            'ignoradas' => $ignoradas,
            'removidas' => $removidas,
        ];
    }

    /**
     * @param  array<string, string>  $mapeamento
     */
    private function validarMapeamentoObrigatorio(array $mapeamento, ?string $layoutPadrao): void
    {
        if (empty($mapeamento['palavra_chave'])) {
            throw new \InvalidArgumentException('Mapeie a coluna da palavra-chave.');
        }

        if (empty($mapeamento['layout']) && ($layoutPadrao === null || $layoutPadrao === '')) {
            throw new \InvalidArgumentException('Mapeie a coluna do layout ou informe o layout padrão.');
        }
    }

    /**
     * @param  array<string, string>  $linha
     * @param  array<string, string>  $mapeamento
     * @return array{dados: array<string, mixed>, erros: list<string>}
     */
    private function extrairRegra(
        array $linha,
        array $mapeamento,
        ?string $layoutPadrao,
        int $numeroLinha
    ): array {
        $erros = [];

        $layout = trim($this->valorMapeado($linha, $mapeamento, 'layout'));
        if ($layout === '' && $layoutPadrao) {
            $layout = $layoutPadrao;
        }

        $palavraChave = trim($this->valorMapeado($linha, $mapeamento, 'palavra_chave'));
        $tipoBusca = $this->validador->normalizarTipoBusca($this->valorMapeado($linha, $mapeamento, 'tipo_busca'));

        if ($layout === '') {
            $erros[] = 'Layout não informado';
        } elseif (!$this->validador->layoutValido($layout)) {
            $erros[] = "Layout inválido: {$layout}";
        }

        if ($palavraChave === '') {
            $erros[] = 'Palavra-chave obrigatória';
        }

        if (!$this->validador->tipoBuscaValido($tipoBusca)) {
            $erros[] = "Tipo de busca inválido: {$tipoBusca}";
        }

        if ($erros !== []) {
            return ['dados' => [], 'erros' => $erros];
        }

        $parteDigitavel = trim($this->valorMapeado($linha, $mapeamento, 'parte_digitavel'));

        return [
            'dados' => [
                'layout_avancado' => $layout,
                'palavra_chave' => $palavraChave,
                'parte_digitavel' => $parteDigitavel !== '' ? $parteDigitavel : null,
                'tipo_busca' => $tipoBusca,
                'conta_contrapartida' => trim($this->valorMapeado($linha, $mapeamento, 'conta_contrapartida')) ?: null,
                'centro_custo' => trim($this->valorMapeado($linha, $mapeamento, 'centro_custo')) ?: null,
                'prioridade' => (int) ($this->valorMapeado($linha, $mapeamento, 'prioridade') ?: 0),
                'descricao' => trim($this->valorMapeado($linha, $mapeamento, 'descricao')) ?: null,
                'ativo' => $this->validador->normalizarAtivo($this->valorMapeado($linha, $mapeamento, 'ativo')),
            ],
            'erros' => [],
        ];
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
        $texto = str_replace(
            ['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'],
            ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'],
            $texto
        );

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
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

    private function chaveRegraModel(RegraAmarracaoDescricao $regra): string
    {
        return $this->chaveRegraArray([
            'layout_avancado' => $regra->layout_avancado ?? '',
            'palavra_chave' => $regra->palavra_chave ?? '',
            'parte_digitavel' => $regra->parte_digitavel ?? '',
            'tipo_busca' => $regra->tipo_busca ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $regra
     */
    private function chaveRegraArray(array $regra): string
    {
        return implode('|', [
            $regra['layout_avancado'] ?? '',
            mb_strtolower(trim($regra['palavra_chave'] ?? '')),
            mb_strtolower(trim($regra['parte_digitavel'] ?? '')),
            $regra['tipo_busca'] ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $regra
     * @return array<string, mixed>
     */
    private function payloadPersistencia(array $regra, int $empresaId): array
    {
        return [
            'empresa_id' => $empresaId,
            'layout_avancado' => $regra['layout_avancado'],
            'palavra_chave' => $regra['palavra_chave'],
            'parte_digitavel' => $regra['parte_digitavel'],
            'tipo_busca' => $regra['tipo_busca'],
            'conta_contrapartida' => $regra['conta_contrapartida'],
            'conta_debito' => null,
            'conta_credito' => null,
            'centro_custo' => $regra['centro_custo'],
            'prioridade' => $regra['prioridade'] ?? 0,
            'descricao' => $regra['descricao'],
            'ativo' => $regra['ativo'] ?? true,
        ];
    }
}
