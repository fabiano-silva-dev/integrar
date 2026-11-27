<?php

namespace App\Livewire\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\PerfilTributario;
use App\Models\Nfse\Servico;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\CatalogoTributarioNfse;
use App\Services\Nfse\CatalogoIndicadoresNfse;
use Illuminate\Support\Facades\DB;
use App\Services\Nfse\PerfisTributariosNfse;
use App\Services\Nfse\PreparacaoNfse;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PerfisTributarios extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    #[Locked]
    public ?int $empresaId = null;

    public string $servicoId = '';
    public string $vigencia = '';
    public bool $confirmado = false;
    public string $buscaClassificacao = '';

    #[Locked]
    public ?int $versaoRevisada = null;

    public string $buscaIndicador = '';

    #[Locked]
    public ?int $versaoIndopRevisada = null;

    public function selecionarIndicador(int $id): void
    {
        $this->empresa();
        $catalogo = app(CatalogoIndicadoresNfse::class);
        $versao = $catalogo->atual();
        abort_unless($versao && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->vigencia), 422);
        $item = $catalogo->consulta((int) $versao->id, $this->vigencia)->where('id', $id)->first();
        if (! $item) {
            $this->addError('indop', 'Indicador indisponível na edição ou competência selecionada.');
            return;
        }
        $this->form['ind_op'] = $item->codigo;
        $this->versaoIndopRevisada = (int) $versao->id;
        $this->confirmado = false;
        $this->resetValidation('indop');
    }

    public function limparIndicador(): void
    {
        $this->empresa();
        $this->form['ind_op'] = null;
        $this->versaoIndopRevisada = null;
        $this->confirmado = false;
    }

    public function selecionarClassificacao(int $id): void
    {
        $this->empresa();
        $catalogo = app(CatalogoTributarioNfse::class);
        $versao = $catalogo->atual();
        abort_unless($versao && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->vigencia), 422);
        $item = $catalogo->consulta((int) $versao->id, $this->vigencia)->where('id', $id)->first();
        if (! $item) {
            $this->addError('catalogo', 'Classificação indisponível na versão ou competência atual. Atualize a busca.');
            return;
        }
        $this->form['cst'] = $item->cst;
        $this->form['cclass_trib'] = $item->cclass_trib;
        $this->versaoRevisada = (int) $versao->id;
        $this->confirmado = false;
        $this->resetValidation('catalogo');
    }

    public function limparClassificacao(): void
    {
        $this->empresa();
        $this->form['cst'] = $this->form['cclass_trib'] = null;
        $this->versaoRevisada = null;
        $this->confirmado = false;
    }

    public array $form = [
        'regime' => '', 'regApTribSN' => null, 'municipio_prestacao' => '',
        'iss' => '', 'iss_retido' => '', 'aliquota_iss' => null,
        'regime_especial' => '', 'deducoes_beneficios' => '',
        'retencoes' => ['irrf' => '', 'pis' => '', 'cofins' => '', 'csll' => '', 'inss' => ''],
        'ibscbs' => '', 'cst' => null, 'cclass_trib' => null, 'ind_op' => null,
        'percentuais_tributos' => ['pTotTribFed' => '', 'pTotTribEst' => '', 'pTotTribMun' => ''],
        'fundamento' => '',
    ];

    public function mount(): void
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $this->vigencia = now()->format('Y-m');
        if ($id = session('empresa_selecionada_id')) {
            $this->empresaId = app(AcessoEmissaoNfse::class)->empresa((int) $id)->id;
            $this->form['regime'] = Configuracao::daEmpresa($this->empresa())->value('regime_tributario') ?? '';
        }
    }

    public function updated(string $campo): void
    {
        if ($campo !== 'confirmado' && $campo !== 'buscaClassificacao' && $campo !== 'buscaIndicador') {
            $this->confirmado = false;
        }
        if ($campo === 'form.regime' && $this->form['regime'] !== 'simples_nacional') {
            $this->form['regApTribSN'] = null;
        }
    }

    public function copiar(int $id): void
    {
        $empresa = $this->empresa();
        $perfil = PerfilTributario::daEmpresa($empresa)->findOrFail($id);
        $this->form = array_replace($this->form, array_intersect_key($perfil->dados, $this->form));
        $this->versaoRevisada = null;
        $this->versaoIndopRevisada = null;
        $this->servicoId = $perfil->servico_id === null ? '' : (string) $perfil->servico_id;
        $this->vigencia = $perfil->vigencia->copy()->addMonthNoOverflow()->format('Y-m');
        $this->confirmado = false;
        $this->resetValidation();
    }

    public function salvar(): void
    {
        $empresa = $this->empresa();
        $this->validate(['confirmado' => 'accepted', 'servicoId' => 'nullable|integer|min:1']);
        $dados = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        app(PerfisTributariosNfse::class)->cadastrar($empresa->id, $this->servicoId === '' ? null : (int) $this->servicoId, $this->vigencia, $dados, $this->versaoRevisada, $this->versaoIndopRevisada);
        $this->confirmado = false;
        $this->resetPage();
        session()->flash('nfse_perfil_mensagem', 'Nova versão registrada. O cadastro não comprova enquadramento fiscal nem autorização de emissão.');
    }

    private function empresa(): Empresa
    {
        abort_unless($this->empresaId && (int) session('empresa_selecionada_id') === $this->empresaId, 403, 'Seleção da empresa mudou. Reabra a tela.');

        return app(AcessoEmissaoNfse::class)->empresa($this->empresaId);
    }

    public function render()
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $empresa = $this->empresaId ? $this->empresa() : null;
        $servicos = collect();
        $perfis = null;
        $cfg = $versaoCatalogo = $classificacaoSelecionada = null;
        $classificacoes = $referencias = collect();
        $versaoIndop = $indopPendente = $indicadorSelecionado = null;
        $indicadores = $referenciasIndop = collect();
        if ($empresa) {
            $servicos = Servico::daEmpresa($empresa)->orderBy('nome')->get(['id', 'nome', 'codigo', 'ativo']);
            $perfis = PerfilTributario::daEmpresa($empresa)->orderByDesc('vigencia')->orderByDesc('id')->paginate(20);
            $cfg = Configuracao::daEmpresa($empresa)->first();
            $catalogo = app(CatalogoTributarioNfse::class);
            $versaoCatalogo = $catalogo->atual();
            if ($versaoCatalogo && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->vigencia)) {
                $classificacoes = $catalogo->buscar((int) $versaoCatalogo->id, $this->vigencia, $this->buscaClassificacao)->limit(25)->get();
                if (filled($this->form['cst']) && filled($this->form['cclass_trib'])) {
                    $classificacaoSelecionada = $catalogo->consulta((int) $versaoCatalogo->id, $this->vigencia)
                        ->where('cst', $this->form['cst'])->where('cclass_trib', $this->form['cclass_trib'])->first();
                }
            }
            $indop = app(CatalogoIndicadoresNfse::class);
            $versaoIndop = $indop->atual();
            $indopPendente = $indop->pendente();
            if ($versaoIndop && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->vigencia)) {
                $indicadores = $indop->buscar((int) $versaoIndop->id, $this->vigencia, $this->buscaIndicador)->limit(25)->get();
                if (filled($this->form['ind_op'])) {
                    $indicadorSelecionado = $indop->consulta((int) $versaoIndop->id, $this->vigencia)
                        ->where('codigo', $this->form['ind_op'])->first();
                }
            }
            $referenciasIndop = DB::table('nfse_indop_codigos as c')->join('nfse_indop_versoes as v', 'v.id', '=', 'c.versao_id')
                ->whereIn('c.id', $perfis->pluck('indop_catalogo_id')->filter()->all())
                ->get(['c.id', 'c.descricao', 'v.versao', 'v.restrita_desde'])->keyBy('id');
            $referencias = DB::table('nfse_catalogo_classificacoes as c')->join('nfse_catalogo_versoes as v', 'v.id', '=', 'c.versao_id')
                ->whereIn('c.id', $perfis->pluck('classificacao_catalogo_id')->filter()->all())
                ->get(['c.id', 'c.descricao', 'v.versao', 'v.fonte', 'v.publicada_em'])->keyBy('id');
        }

        return view('livewire.nfse.perfis-tributarios', compact('empresa', 'servicos', 'perfis', 'cfg', 'versaoCatalogo', 'classificacoes', 'classificacaoSelecionada', 'referencias', 'versaoIndop', 'indopPendente', 'indicadorSelecionado', 'indicadores', 'referenciasIndop'))->layout($this->layout);
    }
}
