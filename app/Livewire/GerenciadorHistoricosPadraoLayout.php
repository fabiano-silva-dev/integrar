<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Models\HistoricoPadraoDescricao;
use App\Models\HistoricoPadraoLayout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class GerenciadorHistoricosPadraoLayout extends Component
{
    use WithFileUploads;

    /** Seleção e upload */
    public $layout_avancado = '';
    public $arquivo;

    /** Após análise: nome e empresa */
    public $nome_sugerido = '';
    public $empresa_id = null;

    /** Grid de descrições (descricao => ['total' => n, 'usar' => bool]) */
    public $descricoes_extraidas = [];

    /** Estado da tela */
    public $analise_concluida = false;
    public $mensagem_status = '';
    public $erro = null;
    public $caminho_csv_temp = null;

    /** Edição: carregar config existente */
    public $historico_padrao_layout_id = null;

    protected function rules()
    {
        $r = [
            'layout_avancado' => 'required|in:dominio,grafeno,sicoob,caixa_federal,ofx,registros,sicredi',
            'nome_sugerido' => 'required|string|max:255',
            'empresa_id' => 'nullable|exists:empresas,id',
        ];
        if (!$this->historico_padrao_layout_id && !$this->analise_concluida) {
            $r['arquivo'] = 'required|file|extensions:csv,txt,pdf,ofx|max:10240';
        }
        return $r;
    }

    public function mount()
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            abort(403, 'Acesso restrito a administradores.');
        }
        $this->mensagem_status = 'Selecione o layout e envie um arquivo do mesmo tipo para extrair as descrições que se repetem.';
    }

    public static function getLayoutsAvancado(): array
    {
        return [
            'dominio' => 'Domínio (TXT)',
            'grafeno' => 'Grafeno (PDF)',
            'sicoob' => 'Sicoob (PDF)',
            'caixa_federal' => 'Caixa Econômica Federal (PDF)',
            'ofx' => 'Formato OFX',
            'registros' => 'Connectere > Contas Financeiras > Diário (CSV)',
            'sicredi' => 'SICREDI (PDF)',
        ];
    }

    private function determinarScriptPython(): string
    {
        $scripts = [
            'dominio' => 'conversor_dominio_txt_csv.py',
            'grafeno' => 'conversor_extrato_grafeno_pdf_csv.py',
            'sicoob' => 'conversor_extrato_sicoob_pdf_csv.py',
            'caixa_federal' => 'conversor_extrato_caixa_federal_pdf_csv.py',
            'ofx' => 'conversor_ofx_csv.py',
            'registros' => 'conversor_registros_csv.py',
            'sicredi' => 'conversor_extrato_sicredi_pdf_csv.py',
        ];
        return $scripts[$this->layout_avancado] ?? 'conversor_registros_csv.py';
    }

    private function getScriptPath(): string
    {
        $script = $this->determinarScriptPython();
        $local = base_path('scripts/' . $script);
        if (file_exists($local)) {
            return $local;
        }
        return '/var/www/html/scripts/' . $script;
    }

    public function analisarArquivo()
    {
        $this->erro = null;
        $this->validate([
            'layout_avancado' => 'required|in:dominio,grafeno,sicoob,caixa_federal,ofx,registros,sicredi',
            'arquivo' => 'required|file|extensions:csv,txt,pdf,ofx|max:10240',
        ]);

        // Para esta tela de configuração, a conta do banco não é relevante:
        // usamos uma conta padrão apenas para satisfazer os scripts de conversão
        $contaBanco = '1.1.1.01';

        try {
            set_time_limit(120);
            $this->mensagem_status = 'Salvando arquivo...';

            $caminho_original = $this->arquivo->store('temp');
            $caminho_completo = Storage::path($caminho_original);

            if (!file_exists($caminho_completo)) {
                throw new \Exception('Arquivo não foi salvo.');
            }

            $script_path = $this->getScriptPath();
            if (!file_exists($script_path)) {
                Storage::delete($caminho_original);
                throw new \Exception('Script do layout não encontrado: ' . $script_path);
            }

            $nome_saida = 'historicos_padrao_' . time() . '.csv';
            $caminho_saida = Storage::path('temp/' . $nome_saida);

            $this->mensagem_status = 'Executando conversão do layout...';

            $script_name = $this->determinarScriptPython();
            if (in_array($this->layout_avancado, ['grafeno', 'sicoob', 'caixa_federal', 'registros', 'sicredi'])) {
                $resultado = Process::run(
                    sprintf('python3 %s "%s" "%s" "%s"', $script_path, $caminho_completo, $caminho_saida, $contaBanco)
                );
            } else {
                $resultado = Process::run(
                    sprintf('python3 %s "%s" "%s"', $script_path, $caminho_completo, $caminho_saida)
                );
            }

            Storage::delete($caminho_original);

            if (!$resultado->successful()) {
                @unlink($caminho_saida);
                throw new \Exception('Erro na conversão: ' . ($resultado->errorOutput() ?: $resultado->output()));
            }

            if (!file_exists($caminho_saida)) {
                throw new \Exception('CSV de saída não foi gerado.');
            }

            $this->caminho_csv_temp = $caminho_saida;
            $this->extrairDescricoesRepetidas($caminho_saida);

            $this->nome_sugerido = $this->nome_sugerido ?: (self::getLayoutsAvancado()[$this->layout_avancado] ?? $this->layout_avancado);
            $this->analise_concluida = true;
            $this->mensagem_status = 'Descrições que se repetem foram detectadas. Revise, marque as que deseja usar para amarração e salve.';
        } catch (\Exception $e) {
            $this->erro = $e->getMessage();
            Log::error('GerenciadorHistoricosPadraoLayout: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function extrairDescricoesRepetidas(string $caminho_csv): void
    {
        $linhas = file($caminho_csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($linhas)) {
            $this->descricoes_extraidas = [];
            return;
        }

        $cabecalho = [];
        $contagem = [];

        foreach ($linhas as $idx => $linha) {
            $dados = str_getcsv($linha, ';');
            if ($idx === 0) {
                foreach ($dados as $i => $col) {
                    $cabecalho[trim($col)] = $i;
                }
                continue;
            }

            $historico = null;
            if (isset($cabecalho['Histórico'])) {
                $historico = isset($dados[$cabecalho['Histórico']]) ? trim($dados[$cabecalho['Histórico']]) : null;
            }
            if ($historico === null && isset($cabecalho['Histórico (Complemento)'])) {
                $historico = isset($dados[$cabecalho['Histórico (Complemento)']]) ? trim($dados[$cabecalho['Histórico (Complemento)']]) : null;
            }

            if ($historico !== null && $historico !== '') {
                // Extrair apenas a parte que realmente se repete entre bancos/layouts
                $base = HistoricoPadraoLayout::extrairParteRepetida($historico);
                if ($base === null || $base === '') {
                    continue;
                }

                $chave = mb_substr($base, 0, 500);
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            }
        }

        // Apenas as que se repetem (ocorrências >= 2)
        $repetidas = array_filter($contagem, fn ($n) => $n >= 2);
        arsort($repetidas);

        $this->descricoes_extraidas = [];
        foreach ($repetidas as $descricao => $total) {
            $this->descricoes_extraidas[] = [
                'descricao' => $descricao,
                'total_ocorrencias' => $total,
                'usar_para_amarracao' => true,
            ];
        }
    }

    public function salvar()
    {
        $this->erro = null;

        if ($this->historico_padrao_layout_id) {
            $this->validate([
                'nome_sugerido' => 'required|string|max:255',
                'empresa_id' => 'nullable|exists:empresas,id',
            ]);
            $layout = HistoricoPadraoLayout::find($this->historico_padrao_layout_id);
            if (!$layout) {
                $this->erro = 'Registro não encontrado.';
                return;
            }
            $layout->update([
                'nome_sugerido' => $this->nome_sugerido,
                'empresa_id' => $this->empresa_id,
            ]);
            if ($this->analise_concluida && is_array($this->descricoes_extraidas) && count($this->descricoes_extraidas) > 0) {
                foreach ($this->descricoes_extraidas as $item) {
                    $desc = mb_substr($item['descricao'] ?? '', 0, 500);
                    if ($desc === '') {
                        continue;
                    }
                    HistoricoPadraoDescricao::updateOrCreate(
                        [
                            'historico_padrao_layout_id' => $layout->id,
                            'descricao' => $desc,
                        ],
                        [
                            'usar_para_amarracao' => $item['usar_para_amarracao'] ?? true,
                            'total_ocorrencias' => $item['total_ocorrencias'] ?? 1,
                        ]
                    );
                }
            }
            session()->flash('message', 'Configuração atualizada com sucesso.');
        } else {
            $this->validate([
                'layout_avancado' => 'required|in:dominio,grafeno,sicoob,caixa_federal,ofx,registros,sicredi',
                'nome_sugerido' => 'required|string|max:255',
                'empresa_id' => 'nullable|exists:empresas,id',
            ]);

            if (empty($this->descricoes_extraidas)) {
                $this->erro = 'Nenhuma descrição repetida para salvar. Analise um arquivo primeiro.';
                return;
            }

            $layout = HistoricoPadraoLayout::create([
                'layout_avancado' => $this->layout_avancado,
                'nome_sugerido' => $this->nome_sugerido,
                'empresa_id' => $this->empresa_id,
            ]);

            foreach ($this->descricoes_extraidas as $item) {
                $desc = mb_substr($item['descricao'] ?? '', 0, 500);
                if ($desc === '') {
                    continue;
                }
                HistoricoPadraoDescricao::create([
                    'historico_padrao_layout_id' => $layout->id,
                    'descricao' => $desc,
                    'usar_para_amarracao' => $item['usar_para_amarracao'] ?? true,
                    'total_ocorrencias' => $item['total_ocorrencias'] ?? 1,
                ]);
            }

            if ($this->caminho_csv_temp && file_exists($this->caminho_csv_temp)) {
                @unlink($this->caminho_csv_temp);
            }

            session()->flash('message', 'Históricos padrão salvos com sucesso.');
        }

        $this->limparFormulario();
    }

    public function editar(int $id): void
    {
        $layout = HistoricoPadraoLayout::with('descricoes')->find($id);
        if (!$layout) {
            return;
        }
        $this->historico_padrao_layout_id = $layout->id;
        $this->layout_avancado = $layout->layout_avancado;
        $this->nome_sugerido = $layout->nome_sugerido;
        $this->empresa_id = $layout->empresa_id;
        $this->descricoes_extraidas = $layout->descricoes->map(fn ($d) => [
            'descricao' => $d->descricao,
            'total_ocorrencias' => $d->total_ocorrencias,
            'usar_para_amarracao' => $d->usar_para_amarracao,
        ])->toArray();
        $this->analise_concluida = true;
        $this->mensagem_status = 'Editando configuração. Altere as opções e salve, ou envie um novo arquivo para reanalisar.';
    }

    public function reanalisar(): void
    {
        $this->historico_padrao_layout_id = null;
        $this->analise_concluida = false;
        $this->descricoes_extraidas = [];
        $this->caminho_csv_temp = null;
        $this->mensagem_status = 'Selecione o layout e envie um arquivo para extrair as descrições.';
    }

    public function limparFormulario(): void
    {
        $this->reset([
            'layout_avancado', 'arquivo', 'nome_sugerido', 'empresa_id',
            'descricoes_extraidas', 'analise_concluida', 'caminho_csv_temp', 'historico_padrao_layout_id', 'erro',
        ]);
        $this->mensagem_status = 'Selecione o layout e envie um arquivo do mesmo tipo para extrair as descrições que se repetem.';
    }

    public function render()
    {
        $configs = HistoricoPadraoLayout::withCount('descricoes')
            ->with('empresa')
            ->orderBy('layout_avancado')
            ->orderBy('nome_sugerido')
            ->get();

        return view('livewire.gerenciador-historicos-padrao-layout', [
            'layouts' => self::getLayoutsAvancado(),
            'empresas' => Empresa::orderBy('nome')->get(),
            'configs' => $configs,
        ]);
    }
}
