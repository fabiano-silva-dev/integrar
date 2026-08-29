<?php

namespace App\Livewire;

use App\Models\AjusteLancamentoItem;
use App\Models\AjusteLancamentoLote;
use App\Models\AlteracaoLog;
use App\Models\Importacao;
use App\Models\Lancamento;
use App\Models\Terceiro;
use App\Services\AjusteLancamentoMassaService;
use App\Services\OperadoraContext;
use App\Services\PlanoContaResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;

class AjustesLancamentosMassa extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public string $aba = 'ajuste';

    public $importacaoId = '';

    public $filtroData = '';

    public $filtroHistorico = '';

    public $filtroContaAtual = '';

    public $filtroValor = '';

    public $filtroTerceiro = '';

    public $filtroTipo = 'todos'; // todos|debito|credito

    public bool $alterarConta = false;

    public bool $alterarHistorico = false;

    public bool $alterarTerceiro = false;

    public $novaConta = '';

    public $novoHistorico = '';

    public $novoTerceiroId = null;

    public $novoTerceiroNome = '';

    public $buscaTerceiro = '';

    public $sugestoesConta = [];

    public $sugestoesContaFiltro = [];

    public bool $confirmando = false;

    public $loteRevertendoId = null;

    public $perPage = 50;

    public $historicoPerPage = 20;

    protected $queryString = [
        'aba' => ['except' => 'ajuste'],
        'importacaoId' => ['except' => '', 'as' => 'importacao'],
        'filtroData' => ['except' => ''],
        'filtroHistorico' => ['except' => ''],
        'filtroContaAtual' => ['except' => ''],
        'filtroValor' => ['except' => ''],
        'filtroTerceiro' => ['except' => ''],
        'filtroTipo' => ['except' => 'todos'],
    ];

    public function mount(): void
    {
        if (request()->filled('importacao')) {
            $this->importacaoId = (string) request()->get('importacao');
        }

        if (! in_array($this->aba, ['ajuste', 'historico'], true)) {
            $this->aba = 'ajuste';
        }
    }

    public function selecionarAba(string $aba): void
    {
        if (! in_array($aba, ['ajuste', 'historico'], true)) {
            return;
        }

        $this->aba = $aba;
        $this->confirmando = false;
        $this->loteRevertendoId = null;
        $this->resetPage();
        $this->resetPage('historicoPage');
    }

    public function updatedImportacaoId(): void
    {
        $this->resetPage();
        $this->confirmando = false;
        $this->limparNovosValores();
        $this->sugestoesConta = [];
        $this->sugestoesContaFiltro = [];
    }

    public function updatedFiltroData(): void
    {
        $this->resetPage();
        $this->confirmando = false;
    }

    public function updatedFiltroHistorico(): void
    {
        $this->resetPage();
        $this->confirmando = false;
    }

    public function updatedFiltroContaAtual($valor): void
    {
        $this->resetPage();
        $this->confirmando = false;

        $empresaId = $this->empresaIdImportacao();
        if (! $empresaId) {
            $this->sugestoesContaFiltro = [];

            return;
        }

        $this->sugestoesContaFiltro = (new PlanoContaResolver)->buscar($empresaId, (string) $valor);
    }

    public function updatedFiltroValor(): void
    {
        $this->resetPage();
        $this->confirmando = false;
    }

    public function updatedFiltroTerceiro(): void
    {
        $this->resetPage();
        $this->confirmando = false;
    }

    public function updatedFiltroTipo(): void
    {
        $this->resetPage();
        $this->confirmando = false;
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedNovaConta($valor): void
    {
        $empresaId = $this->empresaIdImportacao();
        if (! $empresaId) {
            $this->sugestoesConta = [];

            return;
        }

        $this->sugestoesConta = (new PlanoContaResolver)->buscar($empresaId, (string) $valor);
    }

    public function selecionarConta(string $codigo): void
    {
        $this->novaConta = $codigo;
        $this->sugestoesConta = [];
    }

    public function selecionarContaFiltro(string $codigo): void
    {
        $this->filtroContaAtual = $codigo;
        $this->sugestoesContaFiltro = [];
        $this->resetPage();
        $this->confirmando = false;
    }

    public function selecionarTerceiro(int $terceiroId): void
    {
        $terceiro = Terceiro::find($terceiroId);
        if (! $terceiro) {
            return;
        }

        $this->novoTerceiroId = $terceiro->id;
        $this->novoTerceiroNome = $terceiro->nome;
        $this->buscaTerceiro = '';
    }

    public function limparTerceiro(): void
    {
        $this->novoTerceiroId = null;
        $this->novoTerceiroNome = '';
        $this->buscaTerceiro = '';
    }

    public function limparFiltros(): void
    {
        $this->filtroData = '';
        $this->filtroHistorico = '';
        $this->filtroContaAtual = '';
        $this->filtroValor = '';
        $this->filtroTerceiro = '';
        $this->filtroTipo = 'todos';
        $this->sugestoesContaFiltro = [];
        $this->resetPage();
        $this->confirmando = false;
    }

    public function limparNovosValores(): void
    {
        $this->alterarConta = false;
        $this->alterarHistorico = false;
        $this->alterarTerceiro = false;
        $this->novaConta = '';
        $this->novoHistorico = '';
        $this->limparTerceiro();
        $this->sugestoesConta = [];
    }

    public function prepararConfirmacao(): void
    {
        if (! $this->alterarConta && ! $this->alterarHistorico && ! $this->alterarTerceiro) {
            session()->flash('error', 'Selecione ao menos um campo para alterar.');

            return;
        }

        $this->normalizarCamposContaParaValidacao();
        $this->validate($this->regrasAlteracao(), $this->mensagensAlteracao());

        if ($this->getLancamentosQuery()->count() === 0) {
            session()->flash('error', 'Nenhum lançamento corresponde aos filtros.');

            return;
        }

        $this->confirmando = true;
    }

    public function cancelarConfirmacao(): void
    {
        $this->confirmando = false;
    }

    public function aplicarAlteracoes(): void
    {
        if (! $this->alterarConta && ! $this->alterarHistorico && ! $this->alterarTerceiro) {
            session()->flash('error', 'Selecione ao menos um campo para alterar.');
            $this->confirmando = false;

            return;
        }

        $this->normalizarCamposContaParaValidacao();
        $this->validate($this->regrasAlteracao(), $this->mensagensAlteracao());

        $importacao = $this->resolverImportacao();
        if (! $importacao) {
            session()->flash('error', 'Selecione uma importação válida.');
            $this->confirmando = false;

            return;
        }

        $ids = $this->getLancamentosQuery()->pluck('id');
        if ($ids->isEmpty()) {
            session()->flash('error', 'Nenhum lançamento corresponde aos filtros.');
            $this->confirmando = false;

            return;
        }

        $resolver = new PlanoContaResolver;
        $novaConta = null;
        if ($this->alterarConta) {
            $novaConta = $resolver->resolverParaArmazenamento(
                (int) $importacao->empresa_id,
                $this->novaConta
            );
            if ($novaConta === '') {
                session()->flash('error', 'Informe a nova conta.');
                $this->confirmando = false;

                return;
            }
        }

        $terceiro = null;
        if ($this->alterarTerceiro) {
            $terceiro = Terceiro::query()
                ->where('id', $this->novoTerceiroId)
                ->where('empresa_id', $importacao->empresa_id)
                ->first();

            if (! $terceiro) {
                session()->flash('error', 'Terceiro inválido para a empresa da importação.');
                $this->confirmando = false;

                return;
            }
        }

        $atualizados = 0;
        $usuario = Auth::user();
        $usuarioNome = $usuario?->name ?? 'Sistema';
        $loteId = null;

        try {
            DB::transaction(function () use (
                $ids,
                $novaConta,
                $terceiro,
                $importacao,
                $usuario,
                $usuarioNome,
                &$atualizados,
                &$loteId
            ) {
                $lote = AjusteLancamentoLote::create([
                    'empresa_id' => $importacao->empresa_id,
                    'empresa_operadora_id' => $importacao->empresa_operadora_id,
                    'importacao_id' => $importacao->id,
                    'user_id' => $usuario?->id,
                    'usuario_nome' => $usuarioNome,
                    'filtros' => [
                        'data' => $this->filtroData,
                        'historico' => $this->filtroHistorico,
                        'conta_atual' => $this->filtroContaAtual,
                        'valor' => $this->filtroValor,
                        'terceiro' => $this->filtroTerceiro,
                        'tipo' => $this->filtroTipo,
                    ],
                    'alteracoes' => [
                        'conta' => $this->alterarConta ? $novaConta : null,
                        'historico' => $this->alterarHistorico ? trim((string) $this->novoHistorico) : null,
                        'terceiro_id' => $this->alterarTerceiro ? $terceiro?->id : null,
                        'terceiro_nome' => $this->alterarTerceiro ? $terceiro?->nome : null,
                    ],
                    'total_lancamentos' => 0,
                    'total_campos' => 0,
                    'status' => AjusteLancamentoLote::STATUS_APLICADO,
                ]);

                $loteId = $lote->id;
                $bufferItens = [];
                $lancamentosAlterados = [];
                $totalCampos = 0;

                Lancamento::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->chunkById(200, function ($lancamentos) use (
                        $novaConta,
                        $terceiro,
                        $usuarioNome,
                        $lote,
                        &$atualizados,
                        &$bufferItens,
                        &$lancamentosAlterados,
                        &$totalCampos
                    ) {
                        foreach ($lancamentos as $lancamento) {
                            $itens = $this->aplicarEmLancamento(
                                $lancamento,
                                $novaConta,
                                $terceiro,
                                $usuarioNome
                            );

                            if ($itens === []) {
                                continue;
                            }

                            $atualizados++;
                            $lancamentosAlterados[$lancamento->id] = true;

                            foreach ($itens as $item) {
                                $bufferItens[] = [
                                    'ajuste_lancamento_lote_id' => $lote->id,
                                    'empresa_operadora_id' => $lote->empresa_operadora_id,
                                    'lancamento_id' => $lancamento->id,
                                    'campo_alterado' => $item['campo'],
                                    'valor_anterior' => $item['anterior'],
                                    'valor_novo' => $item['novo'],
                                    'tipo_alteracao' => $item['tipo'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                                $totalCampos++;

                                AlteracaoLog::create([
                                    'lancamento_id' => $lancamento->id,
                                    'campo_alterado' => $item['campo'],
                                    'valor_anterior' => $item['anterior'],
                                    'valor_novo' => $item['novo'],
                                    'tipo_alteracao' => $item['tipo'],
                                    'data_alteracao' => now(),
                                ]);
                            }

                            if (count($bufferItens) >= 200) {
                                AjusteLancamentoItem::insertMany($bufferItens);
                                $bufferItens = [];
                            }
                        }
                    });

                if ($bufferItens !== []) {
                    AjusteLancamentoItem::insertMany($bufferItens);
                }

                if ($atualizados === 0) {
                    $lote->delete();
                    $loteId = null;

                    return;
                }

                $lote->update([
                    'total_lancamentos' => count($lancamentosAlterados),
                    'total_campos' => $totalCampos,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Erro no ajuste em massa de lançamentos', [
                'importacao_id' => $importacao->id,
                'erro' => $e->getMessage(),
            ]);
            session()->flash('error', 'Erro ao aplicar alterações: '.$e->getMessage());
            $this->confirmando = false;

            return;
        }

        $this->confirmando = false;
        $this->limparNovosValores();

        if ($atualizados === 0) {
            session()->flash('error', 'Nenhuma alteração efetiva foi necessária nos lançamentos filtrados.');

            return;
        }

        session()->flash(
            'message',
            ($atualizados === 1
                ? '1 lançamento atualizado com sucesso.'
                : "{$atualizados} lançamentos atualizados com sucesso.")
            .($loteId ? " Histórico #{$loteId} registrado." : '')
        );
    }

    public function prepararReversao(int $loteId): void
    {
        $lote = $this->resolverLote($loteId);
        if (! $lote || ! $lote->estaAplicado()) {
            session()->flash('error', 'Ajuste não encontrado ou já revertido.');

            return;
        }

        $this->loteRevertendoId = $lote->id;
    }

    public function cancelarReversao(): void
    {
        $this->loteRevertendoId = null;
    }

    public function confirmarReversao(): void
    {
        $lote = $this->resolverLote((int) $this->loteRevertendoId);
        if (! $lote) {
            session()->flash('error', 'Ajuste não encontrado.');
            $this->loteRevertendoId = null;

            return;
        }

        try {
            $resultado = (new AjusteLancamentoMassaService)->reverter($lote);
        } catch (\Throwable $e) {
            Log::error('Erro ao reverter ajuste em massa', [
                'lote_id' => $lote->id,
                'erro' => $e->getMessage(),
            ]);
            session()->flash('error', $e->getMessage());
            $this->loteRevertendoId = null;

            return;
        }

        $this->loteRevertendoId = null;

        $msg = "Ajuste #{$lote->id} revertido: {$resultado['revertidos']} campo(s) restaurado(s)";
        if ($resultado['pulados'] > 0) {
            $msg .= ", {$resultado['pulados']} pulado(s) (já alterados depois)";
        }
        $msg .= '.';

        session()->flash('message', $msg);
    }

    /**
     * @return list<array{campo:string,anterior:mixed,novo:mixed,tipo:string}>
     */
    private function aplicarEmLancamento(
        Lancamento $lancamento,
        ?string $novaConta,
        ?Terceiro $terceiro,
        string $usuario
    ): array {
        $alteracoes = [];

        if ($this->alterarConta && $novaConta !== null) {
            foreach ($this->camposContaParaAtualizar($lancamento) as $campo) {
                $anterior = $lancamento->{$campo};
                if ((string) $anterior === (string) $novaConta) {
                    continue;
                }

                $campoOriginal = $campo.'_original';
                if (in_array($campoOriginal, $lancamento->getFillable(), true)) {
                    $anteriorOriginal = $lancamento->{$campoOriginal};
                    $lancamento->{$campoOriginal} = $novaConta;
                    if ((string) $anteriorOriginal !== (string) $novaConta) {
                        $alteracoes[] = [
                            'campo' => $campoOriginal,
                            'anterior' => $anteriorOriginal,
                            'novo' => $novaConta,
                            'tipo' => 'conta',
                        ];
                    }
                }

                $lancamento->{$campo} = $novaConta;
                $alteracoes[] = [
                    'campo' => $campo,
                    'anterior' => $anterior,
                    'novo' => $novaConta,
                    'tipo' => 'conta',
                ];
            }
        }

        if ($this->alterarHistorico) {
            $anterior = $lancamento->historico;
            $novo = trim((string) $this->novoHistorico);
            if ((string) $anterior !== $novo) {
                $lancamento->historico = $novo;
                $alteracoes[] = [
                    'campo' => 'historico',
                    'anterior' => $anterior,
                    'novo' => $novo,
                    'tipo' => 'outro',
                ];
            }
        }

        if ($this->alterarTerceiro && $terceiro) {
            $anteriorId = $lancamento->terceiro_id;
            $anteriorNome = $lancamento->nome_empresa;
            if ((int) $anteriorId !== (int) $terceiro->id || (string) $anteriorNome !== (string) $terceiro->nome) {
                $lancamento->terceiro_id = $terceiro->id;
                $lancamento->nome_empresa = $terceiro->nome;
                $alteracoes[] = [
                    'campo' => 'terceiro_id',
                    'anterior' => $anteriorId,
                    'novo' => $terceiro->id,
                    'tipo' => 'outro',
                ];
                if ((string) $anteriorNome !== (string) $terceiro->nome) {
                    $alteracoes[] = [
                        'campo' => 'nome_empresa',
                        'anterior' => $anteriorNome,
                        'novo' => $terceiro->nome,
                        'tipo' => 'outro',
                    ];
                }
            }
        }

        if ($alteracoes === []) {
            return [];
        }

        $lancamento->usuario = $usuario;
        $lancamento->save();

        return $alteracoes;
    }

    /**
     * @return list<string>
     */
    private function camposContaParaAtualizar(Lancamento $lancamento): array
    {
        $contaFiltro = self::normalizarConta((string) $this->filtroContaAtual);

        if ($this->filtroTipo === 'debito') {
            return ['conta_debito'];
        }

        if ($this->filtroTipo === 'credito') {
            return ['conta_credito'];
        }

        if ($contaFiltro !== '') {
            $campos = [];
            if (self::normalizarConta((string) $lancamento->conta_debito) === $contaFiltro) {
                $campos[] = 'conta_debito';
            }
            if (self::normalizarConta((string) $lancamento->conta_credito) === $contaFiltro) {
                $campos[] = 'conta_credito';
            }

            return $campos;
        }

        return ['conta_debito', 'conta_credito'];
    }

    private function normalizarCamposContaParaValidacao(): void
    {
        // Livewire pode enviar conta numérica como int; a regra string falharia.
        if ($this->filtroContaAtual !== null && $this->filtroContaAtual !== '') {
            $this->filtroContaAtual = trim((string) $this->filtroContaAtual);
        } else {
            $this->filtroContaAtual = '';
        }

        if ($this->novaConta !== null && $this->novaConta !== '') {
            $this->novaConta = trim((string) $this->novaConta);
        } else {
            $this->novaConta = '';
        }
    }

    private function regrasAlteracao(): array
    {
        return [
            'importacaoId' => 'required',
            'alterarConta' => 'boolean',
            'alterarHistorico' => 'boolean',
            'alterarTerceiro' => 'boolean',
            'novaConta' => $this->alterarConta ? 'required|max:255' : 'nullable',
            'novoHistorico' => $this->alterarHistorico ? 'required|max:1000' : 'nullable',
            'novoTerceiroId' => $this->alterarTerceiro ? 'required|integer' : 'nullable',
            'filtroContaAtual' => ($this->alterarConta && $this->filtroTipo === 'todos')
                ? 'required|max:255'
                : 'nullable',
        ];
    }

    private function mensagensAlteracao(): array
    {
        return [
            'importacaoId.required' => 'Selecione uma importação.',
            'novaConta.required' => 'Informe a nova conta.',
            'novoHistorico.required' => 'Informe o novo histórico.',
            'novoTerceiroId.required' => 'Selecione o novo terceiro.',
            'filtroContaAtual.required' => 'Para alterar conta com tipo "Todos", informe a conta atual nos filtros.',
        ];
    }

    private function resolverImportacao(): ?Importacao
    {
        if ($this->importacaoId === '' || $this->importacaoId === null) {
            return null;
        }

        $query = Importacao::query()->where('id', $this->importacaoId);
        $empresaId = session('empresa_selecionada_id');
        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        return $query->first();
    }

    private function resolverLote(int $loteId): ?AjusteLancamentoLote
    {
        $query = AjusteLancamentoLote::query()->where('id', $loteId);
        $empresaId = session('empresa_selecionada_id');
        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        return $query->first();
    }

    private function empresaIdImportacao(): ?int
    {
        $importacao = $this->resolverImportacao();

        return $importacao?->empresa_id ? (int) $importacao->empresa_id : null;
    }

    private function getLancamentosQuery()
    {
        $importacao = $this->resolverImportacao();
        if (! $importacao) {
            return Lancamento::query()->whereRaw('1 = 0');
        }

        $query = Lancamento::query()
            ->with(['terceiro'])
            ->where('importacao_id', $importacao->id)
            ->where('empresa_id', $importacao->empresa_id);

        if ($this->filtroData !== '') {
            $query->whereDate('data', $this->filtroData);
        }

        if ($this->filtroHistorico !== '') {
            $query->where('historico', 'like', '%'.$this->filtroHistorico.'%');
        }

        if ($this->filtroTerceiro !== '') {
            $termo = $this->filtroTerceiro;
            $query->where(function ($q) use ($termo) {
                $q->where('nome_empresa', 'like', '%'.$termo.'%')
                    ->orWhereHas('terceiro', function ($sub) use ($termo) {
                        $sub->where('nome', 'like', '%'.$termo.'%');
                    });
            });
        }

        if ($this->filtroValor !== '') {
            $valor = str_replace(['.', ','], ['', '.'], $this->filtroValor);
            if (is_numeric($valor)) {
                $query->where('valor', $valor);
            }
        }

        $contaFiltro = self::normalizarConta((string) $this->filtroContaAtual);
        if ($contaFiltro !== '') {
            $variantes = array_values(array_unique(array_filter([
                trim((string) $this->filtroContaAtual),
                $contaFiltro,
            ], fn ($v) => $v !== '')));

            if ($this->filtroTipo === 'debito') {
                $query->whereIn('conta_debito', $variantes);
            } elseif ($this->filtroTipo === 'credito') {
                $query->whereIn('conta_credito', $variantes);
            } else {
                $query->where(function ($q) use ($variantes) {
                    $q->whereIn('conta_debito', $variantes)
                        ->orWhereIn('conta_credito', $variantes);
                });
            }
        } elseif ($this->filtroTipo === 'debito') {
            $query->whereNotNull('conta_debito')->where('conta_debito', '!=', '');
        } elseif ($this->filtroTipo === 'credito') {
            $query->whereNotNull('conta_credito')->where('conta_credito', '!=', '');
        }

        return $query->orderBy('data')->orderBy('id');
    }

    private function getHistoricoQuery()
    {
        $query = AjusteLancamentoLote::query()
            ->with(['importacao'])
            ->orderByDesc('created_at');

        $empresaId = session('empresa_selecionada_id');
        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        return $query;
    }

    private function buscarTerceirosSugestoes(?string $termo): array
    {
        $termo = trim($termo ?? '');
        if (mb_strlen($termo) < 2) {
            return [];
        }

        $empresaId = $this->empresaIdImportacao() ?: session('empresa_selecionada_id');
        if (! $empresaId) {
            return [];
        }

        return Terceiro::query()
            ->where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where(function ($query) use ($termo) {
                $query->where('nome', 'like', '%'.$termo.'%')
                    ->orWhere('cnpj_cpf', 'like', '%'.$termo.'%');
            })
            ->orderBy('nome')
            ->limit(10)
            ->get(['id', 'nome', 'cnpj_cpf'])
            ->map(fn (Terceiro $terceiro) => [
                'id' => $terceiro->id,
                'nome' => $terceiro->nome,
                'cnpj_cpf' => $terceiro->cnpj_cpf,
            ])
            ->all();
    }

    private static function normalizarConta(string $conta): string
    {
        $c = trim($conta);
        if ($c === '') {
            return '';
        }

        return ltrim($c, '0') ?: '0';
    }

    public function render()
    {
        $vazios = [
            'precisaEscritorio' => false,
            'importacoes' => collect(),
            'lancamentos' => Lancamento::query()->whereRaw('1 = 0')->paginate($this->perPage),
            'totalFiltrado' => 0,
            'empresaTemPlano' => false,
            'descricaoNovaConta' => null,
            'descricaoFiltroConta' => null,
            'terceirosBusca' => [],
            'mapaNomesContas' => [],
            'temAlteracaoSelecionada' => false,
            'historico' => AjusteLancamentoLote::query()->whereRaw('1 = 0')->paginate($this->historicoPerPage, ['*'], 'historicoPage'),
            'loteRevertendo' => null,
        ];

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            return view('livewire.ajustes-lancamentos-massa', array_merge($vazios, [
                'precisaEscritorio' => true,
            ]));
        }

        $empresaId = session('empresa_selecionada_id');
        $importacoesQuery = Importacao::with('empresa')->orderBy('created_at', 'desc');
        if ($empresaId) {
            $importacoesQuery->where('empresa_id', $empresaId);
        }

        $importacoes = $importacoesQuery->get()->map(function (Importacao $importacao) {
            $empresaNome = $importacao->empresa?->nome ?? 'Sem empresa';

            return [
                'id' => $importacao->id,
                'display_text' => "ID: {$importacao->id} - {$importacao->nome_arquivo} - {$empresaNome}",
            ];
        });

        $query = $this->getLancamentosQuery();
        $totalFiltrado = $this->importacaoId !== '' ? (clone $query)->count() : 0;
        $lancamentos = $this->importacaoId !== ''
            ? $query->paginate($this->perPage)
            : Lancamento::query()->whereRaw('1 = 0')->paginate($this->perPage);

        $empresaImportacaoId = $this->empresaIdImportacao();
        $resolver = new PlanoContaResolver;
        $empresaTemPlano = $empresaImportacaoId
            ? $resolver->empresaTemPlanoAtivo($empresaImportacaoId)
            : false;

        $mapaNomesContas = [];
        if ($empresaTemPlano && $lancamentos->count() > 0) {
            $codigos = $lancamentos->getCollection()
                ->flatMap(fn (Lancamento $l) => [$l->conta_debito, $l->conta_credito, $this->novaConta])
                ->all();
            $mapaNomesContas = $resolver->mapearDescricoes($empresaImportacaoId, $codigos);
        }

        $descricaoNovaConta = null;
        if ($empresaTemPlano && trim((string) $this->novaConta) !== '') {
            $mapaNova = $resolver->mapearDescricoes($empresaImportacaoId, [$this->novaConta]);
            $descricaoNovaConta = $mapaNova[self::normalizarConta((string) $this->novaConta)]
                ?? $mapaNova[(string) $this->novaConta]
                ?? null;
        }

        $descricaoFiltroConta = null;
        if ($empresaTemPlano && trim((string) $this->filtroContaAtual) !== '') {
            $mapaFiltro = $resolver->mapearDescricoes($empresaImportacaoId, [$this->filtroContaAtual]);
            $descricaoFiltroConta = $mapaFiltro[self::normalizarConta((string) $this->filtroContaAtual)]
                ?? $mapaFiltro[(string) $this->filtroContaAtual]
                ?? null;
        }

        $historico = $this->getHistoricoQuery()
            ->paginate($this->historicoPerPage, ['*'], 'historicoPage');

        $loteRevertendo = $this->loteRevertendoId
            ? $this->resolverLote((int) $this->loteRevertendoId)
            : null;

        return view('livewire.ajustes-lancamentos-massa', [
            'precisaEscritorio' => false,
            'importacoes' => $importacoes,
            'lancamentos' => $lancamentos,
            'totalFiltrado' => $totalFiltrado,
            'empresaTemPlano' => $empresaTemPlano,
            'descricaoNovaConta' => $descricaoNovaConta,
            'descricaoFiltroConta' => $descricaoFiltroConta,
            'terceirosBusca' => $this->buscarTerceirosSugestoes($this->buscaTerceiro),
            'mapaNomesContas' => $mapaNomesContas,
            'temAlteracaoSelecionada' => $this->alterarConta || $this->alterarHistorico || $this->alterarTerceiro,
            'historico' => $historico,
            'loteRevertendo' => $loteRevertendo,
        ]);
    }
}
