<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class ConversorPdfOfxSicoob extends Component
{
    use WithFileUploads;

    public $arquivo;
    public $status = 'pendente';
    public $progresso = 0;
    public $mensagem_status = '';
    public $arquivo_processado = false;
    public $arquivo_gerado = '';
    public $total_lancamentos = 0;
    public $cooperativa_extraida = '';
    public $numero_conta_extraido = '';
    public $titular_extraido = '';
    public $data_inicial = '';
    public $data_final = '';

    protected $rules = [
        'arquivo' => 'required|file|extensions:pdf|max:10240',
    ];

    protected $messages = [
        'arquivo.required' => 'O arquivo PDF é obrigatório.',
        'arquivo.extensions' => 'O arquivo deve ser um PDF.',
        'arquivo.max' => 'O arquivo não pode ser maior que 10MB.',
    ];

    public function mount()
    {
        $this->mensagem_status = 'Aguardando upload do PDF do extrato Sicoob...';
    }

    public function updatedArquivo()
    {
        $this->resetValidation();
        $this->validateOnly('arquivo');
        $this->mensagem_status = 'Arquivo selecionado. Clique em converter.';
    }

    public function converter()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->validate();

        try {
            $this->status = 'processando';
            $this->progresso = 10;
            $this->mensagem_status = 'Salvando arquivo...';
            $this->arquivo_processado = false;
            $this->arquivo_gerado = '';
            $this->cooperativa_extraida = '';
            $this->numero_conta_extraido = '';
            $this->titular_extraido = '';
            $this->data_inicial = '';
            $this->data_final = '';

            $caminhoOriginal = $this->arquivo->store('temp');
            $caminhoEntrada = Storage::path($caminhoOriginal);

            if (!file_exists($caminhoEntrada)) {
                throw new \Exception('Arquivo não foi salvo corretamente.');
            }

            $nomeOriginal = basename($this->arquivo->getClientOriginalName());
            $nomeOfx = preg_replace('/\.pdf$/i', '.ofx', $nomeOriginal);
            if (!preg_match('/\.ofx$/i', $nomeOfx)) {
                $nomeOfx = pathinfo($nomeOriginal, PATHINFO_FILENAME) . '.ofx';
            }
            $caminhoRelativoSaida = 'exports/' . $nomeOfx;
            $caminhoSaida = Storage::path($caminhoRelativoSaida);

            if (!is_dir(dirname($caminhoSaida))) {
                Storage::makeDirectory('exports');
            }

            $this->progresso = 30;
            $this->mensagem_status = 'Convertendo PDF para OFX...';

            $script = 'conversor_extrato_sicoob_pdf_ofx.py';
            $caminhoScript = '/var/www/html/scripts/' . $script;

            if (!file_exists($caminhoScript)) {
                throw new \Exception("Script Python não encontrado: {$caminhoScript}");
            }

            $comando = sprintf(
                'python3 %s "%s" "%s"',
                $caminhoScript,
                $caminhoEntrada,
                $caminhoSaida
            );

            Log::info('Executando conversão PDF->OFX Sicoob', ['comando' => $comando]);

            $resultado = Process::run($comando);
            $saida = $resultado->output();

            Log::info('Resultado conversão PDF->OFX Sicoob', [
                'sucesso' => $resultado->successful(),
                'saida' => $saida,
                'erro' => $resultado->errorOutput(),
            ]);

            Storage::delete($caminhoOriginal);

            if (!$resultado->successful() || !file_exists($caminhoSaida)) {
                throw new \Exception(trim($resultado->errorOutput() ?: $saida ?: 'Falha na conversão.'));
            }

            $this->total_lancamentos = $this->extrairValorSaida($saida, '/Total de lançamentos:\s*(\d+)/', 'int') ?? 0;
            $this->cooperativa_extraida = $this->extrairValorSaida($saida, '/Cooperativa extraída:\s*(.+)/', 'string') ?? '';
            $this->numero_conta_extraido = $this->extrairValorSaida($saida, '/Conta extraída:\s*(.+)/', 'string') ?? '';
            $this->titular_extraido = $this->extrairValorSaida($saida, '/Titular:\s*(.+)/', 'string') ?? '';
            $this->data_inicial = $this->extrairValorSaida($saida, '/Data inicial:\s*(.+)/', 'string') ?? '';
            $this->data_final = $this->extrairValorSaida($saida, '/Data final:\s*(.+)/', 'string') ?? '';

            $this->progresso = 100;
            $this->status = 'concluida';
            $this->arquivo_processado = true;
            $this->arquivo_gerado = $nomeOfx;
            $this->mensagem_status = 'Conversão concluída com sucesso!';
        } catch (\Exception $e) {
            $this->status = 'erro';
            $this->mensagem_status = 'Erro: ' . $e->getMessage();

            Log::error('Erro na conversão PDF->OFX Sicoob', [
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

    public function resetar()
    {
        $this->reset([
            'arquivo',
            'status',
            'progresso',
            'arquivo_processado',
            'arquivo_gerado',
            'total_lancamentos',
            'cooperativa_extraida',
            'numero_conta_extraido',
            'titular_extraido',
            'data_inicial',
            'data_final',
        ]);
        $this->mensagem_status = 'Aguardando upload do PDF do extrato Sicoob...';
    }

    private function extrairValorSaida(string $saida, string $pattern, string $tipo)
    {
        if (!preg_match($pattern, $saida, $matches)) {
            return null;
        }

        $valor = trim($matches[1]);

        if ($tipo === 'int') {
            return (int) $valor;
        }

        if ($tipo === 'money') {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);

            return (float) $valor;
        }

        return $valor;
    }

    public function render()
    {
        return view('livewire.conversor-pdf-ofx-sicoob');
    }
}
