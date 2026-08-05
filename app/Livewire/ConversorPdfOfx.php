<?php

namespace App\Livewire;

use App\Services\ConversaoPdfOfxService;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class ConversorPdfOfx extends Component
{
    use WithFileUploads;

    public $arquivo;
    public $arquivo_pix;
    public $arquivo_pagamentos;

    /** Novos arquivos do input (anexa aos já enviados). */
    public $arquivos_banrisul_novos = [];

    /** @var array<int, mixed> */
    public $arquivos_banrisul = [];

    /** @var array<int, string> nomes originais do cliente */
    public $nomes_arquivos_banrisul = [];

    /** @var array<int, string|null> extrato|pix|pagamentos|processando|null */
    public $tipos_arquivos_banrisul = [];

    public $erro_classificacao_banrisul = '';
    public $familia_layout = '';
    public $layout_selecionado = '';
    public $status = 'pendente';
    public $progresso = 0;
    public $mensagem_status = '';
    public $arquivo_processado = false;
    public $arquivo_gerado = '';
    public $conversao_id = null;
    public $total_lancamentos = 0;
    public $total_enriquecidos = 0;
    public $total_separados_encargos = 0;
    public $cooperativa_extraida = '';
    public $numero_conta_extraido = '';
    public $titular_extraido = '';
    public $data_inicial = '';
    public $data_final = '';
    public $lancamentos = [];

    protected $servico;

    protected function rules(): array
    {
        $layouts = implode(',', $this->servico->layoutsSuportados());

        $regras = [
            'layout_selecionado' => 'required|in:' . $layouts,
        ];

        if ($this->layoutEhBanrisulEnriquecido()) {
            $regras['arquivos_banrisul'] = 'required|array|size:3';
            $regras['arquivos_banrisul.*'] = 'required|file|extensions:pdf|max:10240';
        } else {
            $regras['arquivo'] = 'required|file|extensions:pdf|max:10240';
        }

        return $regras;
    }

    protected $messages = [
        'arquivo.required' => 'O arquivo PDF do extrato é obrigatório.',
        'arquivo.extensions' => 'O extrato deve ser um PDF.',
        'arquivo.max' => 'O extrato não pode ser maior que 10 MB.',
        'arquivos_banrisul.required' => 'Envie os 3 PDFs: extrato, PIX e pagamentos.',
        'arquivos_banrisul.size' => 'Envie exatamente 3 PDFs: extrato, PIX e pagamentos.',
        'arquivos_banrisul.*.required' => 'Todos os arquivos devem ser PDFs válidos.',
        'arquivos_banrisul.*.extensions' => 'Todos os arquivos devem ser PDF.',
        'arquivos_banrisul.*.max' => 'Cada PDF pode ter no máximo 10 MB.',
        'layout_selecionado.required' => 'Selecione o layout do extrato.',
        'layout_selecionado.in' => 'O layout selecionado não é válido.',
    ];

    public function boot(ConversaoPdfOfxService $servico): void
    {
        $this->servico = $servico;
    }

    public function mount(): void
    {
        $this->mensagem_status = 'Selecione a instituição e envie o PDF do extrato.';
    }

    public function updatedArquivo(): void
    {
        $this->resetValidation();
        $this->validateOnly('arquivo');
        $this->mensagem_status = empty($this->layout_selecionado)
            ? 'Arquivo selecionado. Escolha a instituição e clique em converter.'
            : 'Arquivo selecionado. Clique em converter.';
    }

    public function updatedArquivosBanrisulNovos(): void
    {
        $this->resetValidation();
        $this->erro_classificacao_banrisul = '';

        $novos = array_values(array_filter($this->arquivos_banrisul_novos ?? []));
        $this->arquivos_banrisul_novos = [];

        if ($novos === []) {
            return;
        }

        $indicesParaClassificar = [];

        foreach ($novos as $arquivo) {
            if (count($this->arquivos_banrisul) >= 3) {
                $this->erro_classificacao_banrisul = 'Já há 3 arquivos. Remova um para adicionar outro.';
                break;
            }

            try {
                $this->validateOnlyArquivoBanrisul($arquivo);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->erro_classificacao_banrisul = collect($e->errors())->flatten()->first()
                    ?: 'Arquivo inválido.';
                continue;
            }

            $indice = count($this->arquivos_banrisul);
            $this->arquivos_banrisul[] = $arquivo;
            $this->nomes_arquivos_banrisul[$indice] = $arquivo->getClientOriginalName();
            $this->tipos_arquivos_banrisul[$indice] = 'processando';
            $indicesParaClassificar[] = $indice;
        }

        $this->atualizarMensagemBanrisul();

        if ($indicesParaClassificar !== []) {
            $this->js(
                'setTimeout(() => $wire.classificarPendentesBanrisul([' .
                implode(',', $indicesParaClassificar) .
                ']), 30)'
            );
        }
    }

    public function classificarPendentesBanrisul(array $indices = []): void
    {
        if ($indices === []) {
            foreach ($this->tipos_arquivos_banrisul as $indice => $tipo) {
                if ($tipo === 'processando') {
                    $indices[] = (int) $indice;
                }
            }
        }

        foreach ($indices as $indice) {
            $indice = (int) $indice;
            if (!isset($this->arquivos_banrisul[$indice])) {
                continue;
            }
            if (($this->tipos_arquivos_banrisul[$indice] ?? null) !== 'processando') {
                continue;
            }

            $this->classificarArquivoBanrisulIndice($indice);
        }

        $this->resolverConflitosTiposBanrisul();
        $this->atualizarMensagemBanrisul();
    }

    public function removerArquivoBanrisul(int $indice): void
    {
        if (!isset($this->arquivos_banrisul[$indice])) {
            return;
        }

        unset(
            $this->arquivos_banrisul[$indice],
            $this->nomes_arquivos_banrisul[$indice],
            $this->tipos_arquivos_banrisul[$indice]
        );

        $this->arquivos_banrisul = array_values($this->arquivos_banrisul);
        $this->nomes_arquivos_banrisul = array_values($this->nomes_arquivos_banrisul);
        $this->tipos_arquivos_banrisul = array_values($this->tipos_arquivos_banrisul);
        $this->erro_classificacao_banrisul = '';
        $this->resolverConflitosTiposBanrisul();
        $this->atualizarMensagemBanrisul();
    }

    public function updatedFamiliaLayout($familia): void
    {
        $layoutsPorFamilia = $this->servico->layoutsPdfPorFamilia();
        $opcoes = $layoutsPorFamilia[$familia] ?? [];

        if (count($opcoes) === 1) {
            $this->layout_selecionado = array_key_first($opcoes);
            $this->ajustarMensagemPorLayout();
            return;
        }

        $this->layout_selecionado = '';
        $this->mensagem_status = 'Selecione o modelo do extrato e envie o PDF.';
    }

    public function updatedLayoutSelecionado(): void
    {
        if ($this->layoutEhBanrisulEnriquecido()) {
            $this->arquivo = null;
            $this->arquivo_pix = null;
            $this->arquivo_pagamentos = null;
        } else {
            $this->limparEstadoBanrisul();
            $this->arquivo_pix = null;
            $this->arquivo_pagamentos = null;
        }

        $this->ajustarMensagemPorLayout();
    }

    public function converter(): void
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->validate();

        if ($this->layoutEhBanrisulEnriquecido() && !$this->banrisulProntoParaConverter()) {
            $this->status = 'erro';
            $this->mensagem_status = 'Identifique extrato, PIX e pagamentos antes de converter.';
            return;
        }

        $conversao = null;
        $arquivosTemporarios = [];

        try {
            $this->status = 'processando';
            $this->progresso = 10;
            $this->mensagem_status = 'Salvando arquivos...';
            $this->resetarResultado();

            $ehEnriquecido = $this->layoutEhBanrisulEnriquecido();
            $nomeOriginal = '';
            $caminhoEntrada = '';
            $caminhoPix = null;
            $caminhoPagamentos = null;

            if ($ehEnriquecido) {
                $mapeados = $this->salvarArquivosBanrisulPorTipo($arquivosTemporarios);
                $caminhoEntrada = $mapeados['extrato'];
                $caminhoPix = $mapeados['pix'];
                $caminhoPagamentos = $mapeados['pagamentos'];
                $indiceExtrato = array_search('extrato', $this->tipos_arquivos_banrisul, true);
                $nomeOriginal = $indiceExtrato !== false
                    ? ($this->nomes_arquivos_banrisul[$indiceExtrato] ?? 'banrisul-extrato.pdf')
                    : 'banrisul-extrato.pdf';
            } else {
                $nomeOriginal = basename($this->arquivo->getClientOriginalName());
                $caminhoOriginal = $this->arquivo->store(OperadoraStorage::ensureDirectory('temp'));
                $caminhoEntrada = Storage::path($caminhoOriginal);
                $arquivosTemporarios[] = $caminhoOriginal;

                if (!file_exists($caminhoEntrada)) {
                    throw new \Exception('Arquivo não foi salvo corretamente.');
                }
            }

            $conversao = $this->servico->criarRegistro(
                $this->layout_selecionado,
                $nomeOriginal
            );
            $this->conversao_id = $conversao->id;

            $nomeOfx = preg_replace('/\.pdf$/i', '.ofx', $nomeOriginal);
            if (!preg_match('/\.ofx$/i', $nomeOfx)) {
                $nomeOfx = pathinfo($nomeOriginal, PATHINFO_FILENAME) . '.ofx';
            }

            $dirExports = OperadoraStorage::ensureDirectory('exports');
            $caminhoSaida = Storage::path($dirExports . '/' . $nomeOfx);
            $caminhoPreview = null;

            if ($this->servico->layoutExibeListagemLancamentos($this->layout_selecionado)) {
                $nomePreview = 'preview_' . Str::uuid() . '.json';
                $caminhoPreviewRelativo = OperadoraStorage::ensureDirectory('temp') . '/' . $nomePreview;
                $caminhoPreview = Storage::path($caminhoPreviewRelativo);
                $arquivosTemporarios[] = $caminhoPreviewRelativo;
            }

            $this->progresso = 40;
            $this->mensagem_status = 'Convertendo PDF para OFX...';

            if ($ehEnriquecido) {
                $dados = $this->servico->executarEnriquecido(
                    $this->layout_selecionado,
                    $caminhoEntrada,
                    $caminhoPix,
                    $caminhoPagamentos,
                    $caminhoSaida,
                    $caminhoPreview
                );
            } else {
                $dados = $this->servico->executar(
                    $this->layout_selecionado,
                    $caminhoEntrada,
                    $caminhoSaida,
                    $caminhoPreview
                );
            }

            foreach ($arquivosTemporarios as $arquivoTemp) {
                Storage::delete($arquivoTemp);
            }

            if (!file_exists($caminhoSaida)) {
                throw new \Exception('Arquivo OFX não foi gerado.');
            }

            $this->servico->registrarSucesso($conversao, $dados, $nomeOfx);

            $this->total_lancamentos = $dados['total_lancamentos'] ?? 0;
            $this->total_enriquecidos = $dados['total_enriquecidos'] ?? 0;
            $this->total_separados_encargos = $dados['total_separados_encargos'] ?? 0;
            $this->cooperativa_extraida = $dados['cooperativa'] ?? '';
            $this->numero_conta_extraido = $dados['conta'] ?? '';
            $this->titular_extraido = $dados['titular'] ?? '';
            $this->data_inicial = $dados['data_inicial'] ?? '';
            $this->data_final = $dados['data_final'] ?? '';
            $this->lancamentos = $dados['lancamentos'] ?? [];

            $this->progresso = 100;
            $this->status = 'concluida';
            $this->arquivo_processado = true;
            $this->arquivo_gerado = $nomeOfx;
            $this->mensagem_status = 'Conversão concluída com sucesso!';
        } catch (\Exception $e) {
            foreach ($arquivosTemporarios as $arquivoTemp) {
                Storage::delete($arquivoTemp);
            }

            $this->status = 'erro';
            $this->mensagem_status = 'Erro: ' . $e->getMessage();

            if ($conversao) {
                $this->servico->registrarErro($conversao, $e->getMessage());
            }

            Log::error('Erro na conversão PDF->OFX', [
                'layout' => $this->layout_selecionado,
                'mensagem' => $e->getMessage(),
                'arquivo' => $this->arquivo
                    ? $this->arquivo->getClientOriginalName()
                    : (count($this->arquivos_banrisul) ? 'lote Banrisul' : 'N/A'),
            ]);
        }
    }

    public function downloadArquivo()
    {
        if (!$this->arquivo_gerado) {
            return null;
        }

        $caminho = OperadoraStorage::resolveAbsolutePath('exports', $this->arquivo_gerado);

        if (!$caminho || !file_exists($caminho)) {
            $this->mensagem_status = 'Erro: arquivo não encontrado para download.';
            $this->status = 'erro';

            return null;
        }

        return response()->download($caminho, $this->arquivo_gerado);
    }

    public function resetar(): void
    {
        $this->reset([
            'arquivo',
            'arquivo_pix',
            'arquivo_pagamentos',
            'arquivos_banrisul_novos',
            'arquivos_banrisul',
            'nomes_arquivos_banrisul',
            'tipos_arquivos_banrisul',
            'erro_classificacao_banrisul',
            'familia_layout',
            'layout_selecionado',
            'status',
            'progresso',
            'arquivo_processado',
            'arquivo_gerado',
            'conversao_id',
            'total_lancamentos',
            'total_enriquecidos',
            'total_separados_encargos',
            'cooperativa_extraida',
            'numero_conta_extraido',
            'titular_extraido',
            'data_inicial',
            'data_final',
            'lancamentos',
        ]);
        $this->mensagem_status = 'Selecione a instituição e envie o PDF do extrato.';
    }

    private function layoutEhBanrisulEnriquecido(): bool
    {
        return $this->layout_selecionado === 'banrisul_enriquecido';
    }

    private function limparEstadoBanrisul(): void
    {
        $this->arquivos_banrisul_novos = [];
        $this->arquivos_banrisul = [];
        $this->nomes_arquivos_banrisul = [];
        $this->tipos_arquivos_banrisul = [];
        $this->erro_classificacao_banrisul = '';
    }

    private function ajustarMensagemPorLayout(): void
    {
        if ($this->layoutEhBanrisulEnriquecido()) {
            $this->mensagem_status = 'Envie os 3 PDFs Banrisul (extrato, PIX e pagamentos).';
            return;
        }

        $this->mensagem_status = 'Instituição selecionada. Envie o PDF do extrato.';
    }

    private function validateOnlyArquivoBanrisul($arquivo): void
    {
        validator(
            ['arquivo' => $arquivo],
            ['arquivo' => 'required|file|extensions:pdf|max:10240'],
            [
                'arquivo.extensions' => 'O arquivo deve ser um PDF.',
                'arquivo.max' => 'Cada PDF pode ter no máximo 10 MB.',
            ]
        )->validate();
    }

    private function classificarArquivoBanrisulIndice(int $indice): void
    {
        $arquivo = $this->arquivos_banrisul[$indice] ?? null;
        if (!$arquivo) {
            return;
        }

        $relativo = null;

        try {
            $relativo = $arquivo->store(OperadoraStorage::ensureDirectory('temp'));
            $caminho = Storage::path($relativo);
            $resultado = $this->servico->classificarUmArquivoBanrisul($caminho);

            if (!($resultado['ok'] ?? false) || empty($resultado['tipo'])) {
                $this->tipos_arquivos_banrisul[$indice] = null;
                $this->erro_classificacao_banrisul = $resultado['erro']
                    ?? 'Não foi possível identificar um dos PDFs.';
                return;
            }

            $this->tipos_arquivos_banrisul[$indice] = $resultado['tipo'];
        } catch (\Exception $e) {
            $this->tipos_arquivos_banrisul[$indice] = null;
            $this->erro_classificacao_banrisul = $e->getMessage();
        } finally {
            if ($relativo) {
                Storage::delete($relativo);
            }
        }
    }

    private function resolverConflitosTiposBanrisul(): void
    {
        $tiposValidos = ['extrato', 'pix', 'pagamentos'];

        // Reavalia conflitos a partir do tipo real (duplicado:X volta a X)
        foreach ($this->tipos_arquivos_banrisul as $indice => $tipo) {
            if (is_string($tipo) && str_starts_with($tipo, 'duplicado:')) {
                $this->tipos_arquivos_banrisul[$indice] = substr($tipo, strlen('duplicado:'));
            }
        }

        $porTipo = [];
        foreach ($this->tipos_arquivos_banrisul as $indice => $tipo) {
            if (!in_array($tipo, $tiposValidos, true)) {
                continue;
            }
            $porTipo[$tipo][] = (int) $indice;
        }

        foreach ($porTipo as $tipo => $indices) {
            if (count($indices) <= 1) {
                continue;
            }

            // Mantém o primeiro; marca os demais como duplicados do mesmo padrão
            foreach (array_slice($indices, 1) as $indiceDuplicado) {
                $this->tipos_arquivos_banrisul[$indiceDuplicado] = 'duplicado:' . $tipo;
            }
        }
    }

    private function atualizarMensagemBanrisul(): void
    {
        $total = count($this->arquivos_banrisul);
        if ($total === 0) {
            $this->mensagem_status = 'Envie os 3 PDFs Banrisul (extrato, PIX e pagamentos).';
            return;
        }

        if ($this->banrisulProntoParaConverter()) {
            $this->mensagem_status = 'Arquivos prontos. Clique em converter.';
            return;
        }

        if (in_array('processando', $this->tipos_arquivos_banrisul, true)) {
            $this->mensagem_status = 'Identificando arquivos...';
            return;
        }

        $faltam = 3 - $total;
        if ($faltam > 0) {
            $this->mensagem_status = $faltam === 1
                ? 'Falta 1 PDF.'
                : "Faltam {$faltam} PDFs.";
            return;
        }

        $this->mensagem_status = 'Confira os tipos identificados antes de converter.';
    }

    private function banrisulProntoParaConverter(): bool
    {
        if (count($this->arquivos_banrisul) !== 3) {
            return false;
        }

        $tipos = array_values($this->tipos_arquivos_banrisul);
        sort($tipos);

        return $tipos === ['extrato', 'pagamentos', 'pix'];
    }

    /**
     * @param  array<int, string>  $arquivosTemporarios
     * @return array{extrato: string, pix: string, pagamentos: string}
     */
    private function salvarArquivosBanrisulPorTipo(array &$arquivosTemporarios): array
    {
        $mapa = [];

        foreach ($this->arquivos_banrisul as $indice => $arquivo) {
            $tipo = $this->tipos_arquivos_banrisul[$indice] ?? null;
            if (!in_array($tipo, ['extrato', 'pix', 'pagamentos'], true)) {
                throw new \RuntimeException('Há arquivo sem tipo identificado. Remova e envie novamente.');
            }

            $relativo = $arquivo->store(OperadoraStorage::ensureDirectory('temp'));
            $arquivosTemporarios[] = $relativo;
            $mapa[$tipo] = Storage::path($relativo);
        }

        foreach (['extrato', 'pix', 'pagamentos'] as $tipo) {
            if (empty($mapa[$tipo])) {
                throw new \RuntimeException('Faltou identificar um dos arquivos necessários.');
            }
        }

        return $mapa;
    }

    private function resetarResultado(): void
    {
        $this->arquivo_processado = false;
        $this->arquivo_gerado = '';
        $this->conversao_id = null;
        $this->total_lancamentos = 0;
        $this->total_enriquecidos = 0;
        $this->total_separados_encargos = 0;
        $this->cooperativa_extraida = '';
        $this->numero_conta_extraido = '';
        $this->titular_extraido = '';
        $this->data_inicial = '';
        $this->data_final = '';
        $this->lancamentos = [];
    }

    public function render()
    {
        if (!empty($this->layout_selecionado) && empty($this->familia_layout)) {
            $familia = $this->servico->familiaDoLayout($this->layout_selecionado);
            if ($familia) {
                $this->familia_layout = $familia;
            }
        }

        return view('livewire.conversor-pdf-ofx', [
            'familiasLayout' => $this->servico->familiasLayout(),
            'layoutsDisponiveis' => $this->servico->layoutsPdfPorFamilia()[$this->familia_layout] ?? [],
            'miniaturasLayout' => $this->servico->miniaturasPorFamilia($this->familia_layout),
            'layoutRequerAuxiliares' => $this->layoutEhBanrisulEnriquecido(),
            'layoutExibeListagem' => $this->servico->layoutExibeListagemLancamentos($this->layout_selecionado),
            'banrisulPronto' => $this->banrisulProntoParaConverter(),
            'rotulosTiposBanrisul' => [
                'extrato' => 'Extrato',
                'pix' => 'Relatório de PIX',
                'pagamentos' => 'Relatório de pagamentos',
            ],
        ]);
    }
}
