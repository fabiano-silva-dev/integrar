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
            'arquivo' => 'required|file|extensions:pdf|max:10240',
            'layout_selecionado' => 'required|in:' . $layouts,
        ];

        if ($this->servico->layoutRequerArquivosAuxiliares($this->layout_selecionado)) {
            $regras['arquivo_pix'] = 'required|file|extensions:pdf|max:10240';
            $regras['arquivo_pagamentos'] = 'required|file|extensions:pdf|max:10240';
        }

        return $regras;
    }

    protected $messages = [
        'arquivo.required' => 'O arquivo PDF do extrato é obrigatório.',
        'arquivo.extensions' => 'O extrato deve ser um PDF.',
        'arquivo.max' => 'O extrato não pode ser maior que 10 MB.',
        'arquivo_pix.required' => 'O relatório de PIX é obrigatório para este layout.',
        'arquivo_pix.extensions' => 'O relatório de PIX deve ser um PDF.',
        'arquivo_pix.max' => 'O relatório de PIX não pode ser maior que 10 MB.',
        'arquivo_pagamentos.required' => 'O relatório de pagamentos é obrigatório para este layout.',
        'arquivo_pagamentos.extensions' => 'O relatório de pagamentos deve ser um PDF.',
        'arquivo_pagamentos.max' => 'O relatório de pagamentos não pode ser maior que 10 MB.',
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

    public function updatedArquivoPix(): void
    {
        $this->resetValidation();
        $this->validateOnly('arquivo_pix');
    }

    public function updatedArquivoPagamentos(): void
    {
        $this->resetValidation();
        $this->validateOnly('arquivo_pagamentos');
    }

    public function updatedFamiliaLayout($familia): void
    {
        $layoutsPorFamilia = $this->servico->layoutsPdfPorFamilia();
        $opcoes = $layoutsPorFamilia[$familia] ?? [];

        if (count($opcoes) === 1) {
            $this->layout_selecionado = array_key_first($opcoes);
            $this->mensagem_status = 'Instituição selecionada. Envie o PDF do extrato.';
            return;
        }

        $this->layout_selecionado = '';
        $this->mensagem_status = 'Selecione o modelo do extrato e envie o PDF.';
    }

    public function updatedLayoutSelecionado(): void
    {
        if (!$this->servico->layoutRequerArquivosAuxiliares($this->layout_selecionado)) {
            $this->arquivo_pix = null;
            $this->arquivo_pagamentos = null;
        }
    }

    public function converter(): void
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->validate();

        $conversao = null;
        $arquivosTemporarios = [];

        try {
            $this->status = 'processando';
            $this->progresso = 10;
            $this->mensagem_status = 'Salvando arquivos...';
            $this->resetarResultado();

            $nomeOriginal = basename($this->arquivo->getClientOriginalName());
            $conversao = $this->servico->criarRegistro(
                $this->layout_selecionado,
                $nomeOriginal
            );
            $this->conversao_id = $conversao->id;

            $caminhoOriginal = $this->arquivo->store(OperadoraStorage::ensureDirectory('temp'));
            $caminhoEntrada = Storage::path($caminhoOriginal);
            $arquivosTemporarios[] = $caminhoOriginal;

            if (!file_exists($caminhoEntrada)) {
                throw new \Exception('Arquivo não foi salvo corretamente.');
            }

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

            if ($this->servico->layoutRequerArquivosAuxiliares($this->layout_selecionado)) {
                $caminhoPix = $this->salvarArquivoAuxiliar($this->arquivo_pix, $arquivosTemporarios);
                $caminhoPagamentos = $this->salvarArquivoAuxiliar($this->arquivo_pagamentos, $arquivosTemporarios);

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
                'arquivo' => $this->arquivo ? $this->arquivo->getClientOriginalName() : 'N/A',
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

    private function salvarArquivoAuxiliar($arquivo, array &$arquivosTemporarios): string
    {
        $caminhoRelativo = $arquivo->store(OperadoraStorage::ensureDirectory('temp'));
        $arquivosTemporarios[] = $caminhoRelativo;
        $caminhoAbsoluto = Storage::path($caminhoRelativo);

        if (!file_exists($caminhoAbsoluto)) {
            throw new \Exception('Arquivo auxiliar não foi salvo corretamente.');
        }

        return $caminhoAbsoluto;
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
            'layoutRequerAuxiliares' => $this->servico->layoutRequerArquivosAuxiliares($this->layout_selecionado),
            'layoutExibeListagem' => $this->servico->layoutExibeListagemLancamentos($this->layout_selecionado),
        ]);
    }
}
