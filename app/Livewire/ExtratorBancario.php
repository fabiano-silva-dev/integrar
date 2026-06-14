<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Lancamento;
use App\Models\Empresa;
use App\Rules\EmpresaDoEscritorio;
use App\Services\OperadoraStorage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExtratorBancario extends Component
{
    public $contaBanco = '';
    public $saldoInicial = '';
    public $saldoFinal = '';
    public $dataInicial = '';
    public $dataFinal = '';
    public $empresaId = '';
    public $extrato = [];
    public $saldoCalculado = 0;
    public $diferenca = 0;
    public $empresas = [];
    public $editandoLancamento = null;
    public $valorEditado = '';

    protected function rules()
    {
        return [
            'contaBanco' => 'required',
            'saldoInicial' => 'required|numeric',
            'saldoFinal' => 'required|numeric',
            'dataInicial' => 'required|date',
            'dataFinal' => 'required|date|after_or_equal:dataInicial',
            'empresaId' => ['required', new EmpresaDoEscritorio()],
        ];
    }

    protected $messages = [
        'contaBanco.required' => 'A conta do banco é obrigatória',
        'saldoInicial.required' => 'O saldo inicial é obrigatório',
        'saldoInicial.numeric' => 'O saldo inicial deve ser um número',
        'saldoFinal.required' => 'O saldo final é obrigatório',
        'saldoFinal.numeric' => 'O saldo final deve ser um número',
        'dataInicial.required' => 'A data inicial é obrigatória',
        'dataInicial.date' => 'A data inicial deve ser uma data válida',
        'dataFinal.required' => 'A data final é obrigatória',
        'dataFinal.date' => 'A data final deve ser uma data válida',
        'dataFinal.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial',
        'empresaId.required' => 'A empresa é obrigatória',
        'empresaId.exists' => 'Empresa selecionada não existe',
    ];

    public function mount()
    {
        $this->empresas = Empresa::orderBy('nome')->get();
        $this->dataInicial = now()->startOfMonth()->format('Y-m-d');
        $this->dataFinal = now()->endOfMonth()->format('Y-m-d');
    }

    public function gerarExtrato()
    {
        $this->validate();

        $dataInicial = Carbon::parse($this->dataInicial);
        $dataFinal = Carbon::parse($this->dataFinal);
        $saldoInicial = (float) $this->saldoInicial;
        $saldoAtual = $saldoInicial;

        // Buscar lançamentos da conta no período
        $lancamentos = Lancamento::where('empresa_id', $this->empresaId)
            ->where(function ($query) {
                $query->where('conta_debito', $this->contaBanco)
                      ->orWhere('conta_credito', $this->contaBanco);
            })
            ->whereBetween('data', [$dataInicial, $dataFinal])
            ->orderBy('data')
            ->orderBy('id')
            ->get();



        $extrato = [];
        $dataAtual = $dataInicial->copy();

        // Gerar array com todas as datas do período
        while ($dataAtual <= $dataFinal) {
            $extrato[$dataAtual->format('Y-m-d')] = [
                'data' => $dataAtual->format('d/m/Y'),
                'saldo_inicial' => $saldoAtual,
                'lancamentos' => [],
                'saldo_final' => $saldoAtual,
                'movimentacao_dia' => 0
            ];
            $dataAtual->addDay();
        }

        // Processar lançamentos
        foreach ($lancamentos as $lancamento) {
            $dataLancamento = $lancamento->data->format('Y-m-d');
            
            if (isset($extrato[$dataLancamento])) {
                $valor = (float) $lancamento->valor;
                $tipo = '';
                $afetaConta = false;
                
                // Determinar se é débito ou crédito na conta
                if ($lancamento->conta_debito === $this->contaBanco) {
                    $tipo = 'Débito';
                    $extrato[$dataLancamento]['movimentacao_dia'] += $valor;
                    $afetaConta = true;
                } elseif ($lancamento->conta_credito === $this->contaBanco) {
                    $tipo = 'Crédito';
                    $extrato[$dataLancamento]['movimentacao_dia'] -= $valor;
                    $afetaConta = true;
                }

                // Só adicionar ao extrato se realmente afeta a conta
                if ($afetaConta) {
                    // Determinar qual é a conta contábil (a que não é do banco)
                    $contaContabil = '';
                    if ($lancamento->conta_debito === $this->contaBanco) {
                        $contaContabil = $lancamento->conta_credito;
                    } elseif ($lancamento->conta_credito === $this->contaBanco) {
                        $contaContabil = $lancamento->conta_debito;
                    }
                    
                    $extrato[$dataLancamento]['lancamentos'][] = [
                        'id' => $lancamento->id,
                        'historico' => $lancamento->historico,
                        'valor' => $valor,
                        'tipo' => $tipo,
                        'terceiro' => $lancamento->terceiro ? $lancamento->terceiro->nome : $lancamento->terceiro,
                        'conta_debito' => $lancamento->conta_debito,
                        'conta_credito' => $lancamento->conta_credito,
                        'conta_contabil' => $contaContabil,
                    ];
                }
            }
        }

        // Calcular saldos finais para cada dia
        $saldoAcumulado = $saldoInicial;
        foreach ($extrato as $data => &$dia) {
            $dia['saldo_inicial'] = $saldoAcumulado;
            $saldoAcumulado += $dia['movimentacao_dia'];
            $dia['saldo_final'] = $saldoAcumulado;
        }

        $this->extrato = $extrato;
        $this->saldoCalculado = $saldoAcumulado;
        $this->diferenca = (float) $this->saldoFinal - $saldoAcumulado;


    }

    public function limpar()
    {
        $this->reset(['extrato', 'saldoCalculado', 'diferenca', 'editandoLancamento', 'valorEditado']);
    }

    public function iniciarEdicao($data, $index)
    {
        // Converter formato de data de dd/mm/yyyy para yyyy-mm-dd
        $dataFormatada = \Carbon\Carbon::createFromFormat('d/m/Y', $data)->format('Y-m-d');
        
        $this->editandoLancamento = $data . '_' . $index;
        $this->valorEditado = $this->extrato[$dataFormatada]['lancamentos'][$index]['valor'];
    }

    public function cancelarEdicao()
    {
        $this->editandoLancamento = null;
        $this->valorEditado = '';
    }

    public function salvarEdicao()
    {
        if ($this->editandoLancamento) {
            [$data, $index] = explode('_', $this->editandoLancamento);
            $index = (int) $index;
            
            // Converter formato de data de dd/mm/yyyy para yyyy-mm-dd
            $dataFormatada = \Carbon\Carbon::createFromFormat('d/m/Y', $data)->format('Y-m-d');
            
            if (isset($this->extrato[$dataFormatada]['lancamentos'][$index])) {
                $novoValor = (float) $this->valorEditado;
                $valorAntigo = $this->extrato[$dataFormatada]['lancamentos'][$index]['valor'];
                
                // Atualizar o valor no extrato
                $this->extrato[$dataFormatada]['lancamentos'][$index]['valor'] = $novoValor;
                
                // Recalcular movimentação do dia
                $movimentacaoDia = 0;
                foreach ($this->extrato[$dataFormatada]['lancamentos'] as $lancamento) {
                    if ($lancamento['tipo'] === 'Débito') {
                        $movimentacaoDia += $lancamento['valor'];
                    } else {
                        $movimentacaoDia -= $lancamento['valor'];
                    }
                }
                $this->extrato[$dataFormatada]['movimentacao_dia'] = $movimentacaoDia;
                
                // Recalcular saldos de todos os dias subsequentes
                $saldoAcumulado = (float) $this->saldoInicial;
                foreach ($this->extrato as $dataDia => &$dia) {
                    $dia['saldo_inicial'] = $saldoAcumulado;
                    $saldoAcumulado += $dia['movimentacao_dia'];
                    $dia['saldo_final'] = $saldoAcumulado;
                }
                
                // Atualizar saldo calculado e diferença
                $this->saldoCalculado = $saldoAcumulado;
                $this->diferenca = (float) $this->saldoFinal - $saldoAcumulado;
                
                // Salvar no banco de dados
                $lancamentoId = $this->extrato[$dataFormatada]['lancamentos'][$index]['id'] ?? null;
                if ($lancamentoId) {
                    $lancamento = Lancamento::find($lancamentoId);
                    if ($lancamento) {
                        $lancamento->valor = $novoValor;
                        $lancamento->save();
                    }
                }
            }
        }
        
        $this->editandoLancamento = null;
        $this->valorEditado = '';
    }

    public function exportarCsv()
    {
        if (empty($this->extrato)) {
            return;
        }

        $filename = 'extrato_bancario_' . $this->contaBanco . '_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = OperadoraStorage::exportsDirectory() . '/' . $filename;

        if (!file_exists(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        $handle = fopen($filepath, 'w');

        // Cabeçalho
        fputcsv($handle, [
            'Data',
            'Saldo Inicial',
            'Histórico',
            'Débito',
            'Crédito',
            'Saldo Final',
            'Terceiro',
            'Conta Débito',
            'Conta Crédito'
        ]);

        foreach ($this->extrato as $dia) {
            if (!empty($dia['lancamentos'])) {
                // Dia com movimentação
                foreach ($dia['lancamentos'] as $index => $lancamento) {
                    fputcsv($handle, [
                        $index === 0 ? $dia['data'] : '',
                        $index === 0 ? number_format($dia['saldo_inicial'], 2, ',', '.') : '',
                        $lancamento['historico'],
                        $lancamento['tipo'] === 'Débito' ? number_format($lancamento['valor'], 2, ',', '.') : '',
                        $lancamento['tipo'] === 'Crédito' ? number_format($lancamento['valor'], 2, ',', '.') : '',
                        $index === count($dia['lancamentos']) - 1 ? number_format($dia['saldo_final'], 2, ',', '.') : '',
                        $lancamento['terceiro'] ?? '',
                        $lancamento['conta_debito'],
                        $lancamento['conta_credito']
                    ]);
                }
            }
        }

        // Resumo
        fputcsv($handle, []);
        fputcsv($handle, ['RESUMO']);
        fputcsv($handle, ['Saldo Inicial Informado', number_format($this->saldoInicial, 2, ',', '.')]);
        fputcsv($handle, ['Saldo Final Calculado', number_format($this->saldoCalculado, 2, ',', '.')]);
        fputcsv($handle, ['Saldo Final Informado', number_format($this->saldoFinal, 2, ',', '.')]);
        fputcsv($handle, ['Diferença', number_format($this->diferenca, 2, ',', '.')]);

        fclose($handle);

        return redirect()->route('download.arquivo', $filename);
    }

    public function render()
    {
        return view('livewire.extrator-bancario');
    }
} 