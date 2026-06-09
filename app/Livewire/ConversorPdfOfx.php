<?php

namespace App\Livewire;

use App\Services\ConversaoPdfOfxService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class ConversorPdfOfx extends Component
{
    use WithFileUploads;

    public $arquivo;
    public $familia_layout = '';
    public $layout_selecionado = '';
    public $status = 'pendente';
    public $progresso = 0;
    public $mensagem_status = '';
    public $arquivo_processado = false;
    public $arquivo_gerado = '';
    public $conversao_id = null;
    public $total_lancamentos = 0;
    public $cooperativa_extraida = '';
    public $numero_conta_extraido = '';
    public $titular_extraido = '';
    public $data_inicial = '';
    public $data_final = '';

    protected $servico;

    protected $rules = [
        'arquivo' => 'required|file|extensions:pdf|max:10240',
        'layout_selecionado' => 'required|in:grafeno,sicoob,caixa_federal,caixa,sicredi,santander,itau,bradesco',
    ];

    protected $messages = [
        'arquivo.required' => 'O arquivo PDF é obrigatório.',
        'arquivo.extensions' => 'O arquivo deve ser um PDF.',
        'arquivo.max' => 'O arquivo não pode ser maior que 10MB.',
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
        $this->mensagem_status = 'Arquivo selecionado. Escolha o layout e clique em converter.';
    }

    public function updatedFamiliaLayout($familia): void
    {
        $layoutsPorFamilia = $this->servico->layoutsPdfPorFamilia();
        $opcoes = $layoutsPorFamilia[$familia] ?? [];

        if (count($opcoes) === 1) {
            $this->layout_selecionado = array_key_first($opcoes);
            return;
        }

        $this->layout_selecionado = '';
    }

    public function converter(): void
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->validate();

        $conversao = null;

        try {
            $this->status = 'processando';
            $this->progresso = 10;
            $this->mensagem_status = 'Salvando arquivo...';
            $this->resetarResultado();

            $nomeOriginal = basename($this->arquivo->getClientOriginalName());
            $conversao = $this->servico->criarRegistro(
                $this->layout_selecionado,
                $nomeOriginal
            );
            $this->conversao_id = $conversao->id;

            $caminhoOriginal = $this->arquivo->store('temp');
            $caminhoEntrada = Storage::path($caminhoOriginal);

            if (!file_exists($caminhoEntrada)) {
                throw new \Exception('Arquivo não foi salvo corretamente.');
            }

            $nomeOfx = preg_replace('/\.pdf$/i', '.ofx', $nomeOriginal);
            if (!preg_match('/\.ofx$/i', $nomeOfx)) {
                $nomeOfx = pathinfo($nomeOriginal, PATHINFO_FILENAME) . '.ofx';
            }

            $caminhoSaida = Storage::path('exports/' . $nomeOfx);
            if (!is_dir(dirname($caminhoSaida))) {
                Storage::makeDirectory('exports');
            }

            $this->progresso = 40;
            $this->mensagem_status = 'Convertendo PDF para OFX...';

            $dados = $this->servico->executar($this->layout_selecionado, $caminhoEntrada, $caminhoSaida);

            Storage::delete($caminhoOriginal);

            if (!file_exists($caminhoSaida)) {
                throw new \Exception('Arquivo OFX não foi gerado.');
            }

            $this->servico->registrarSucesso($conversao, $dados, $nomeOfx);

            $this->total_lancamentos = $dados['total_lancamentos'] ?? 0;
            $this->cooperativa_extraida = $dados['cooperativa'] ?? '';
            $this->numero_conta_extraido = $dados['conta'] ?? '';
            $this->titular_extraido = $dados['titular'] ?? '';
            $this->data_inicial = $dados['data_inicial'] ?? '';
            $this->data_final = $dados['data_final'] ?? '';

            $this->progresso = 100;
            $this->status = 'concluida';
            $this->arquivo_processado = true;
            $this->arquivo_gerado = $nomeOfx;
            $this->mensagem_status = 'Conversão concluída com sucesso!';
        } catch (\Exception $e) {
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

        $caminho = storage_path('app/exports/' . basename($this->arquivo_gerado));

        if (!file_exists($caminho)) {
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
            'familia_layout',
            'layout_selecionado',
            'status',
            'progresso',
            'arquivo_processado',
            'arquivo_gerado',
            'conversao_id',
            'total_lancamentos',
            'cooperativa_extraida',
            'numero_conta_extraido',
            'titular_extraido',
            'data_inicial',
            'data_final',
        ]);
        $this->mensagem_status = 'Selecione a instituição e envie o PDF do extrato.';
    }

    private function resetarResultado(): void
    {
        $this->arquivo_processado = false;
        $this->arquivo_gerado = '';
        $this->conversao_id = null;
        $this->total_lancamentos = 0;
        $this->cooperativa_extraida = '';
        $this->numero_conta_extraido = '';
        $this->titular_extraido = '';
        $this->data_inicial = '';
        $this->data_final = '';
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
        ]);
    }
}
