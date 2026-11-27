<?php

namespace App\Livewire\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\PerfilTributario;
use App\Models\Nfse\Servico;
use App\Services\Nfse\AcessoEmissaoNfse;
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
        if ($campo !== 'confirmado') {
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
        $this->form = array_replace($this->form, $perfil->dados);
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
        app(PerfisTributariosNfse::class)->cadastrar($empresa->id, $this->servicoId === '' ? null : (int) $this->servicoId, $this->vigencia, $dados);
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
        $cfg = null;
        if ($empresa) {
            $servicos = Servico::daEmpresa($empresa)->orderBy('nome')->get(['id', 'nome', 'codigo', 'ativo']);
            $perfis = PerfilTributario::daEmpresa($empresa)->orderByDesc('vigencia')->orderByDesc('id')->paginate(20);
            $cfg = Configuracao::daEmpresa($empresa)->first();
        }

        return view('livewire.nfse.perfis-tributarios', compact('empresa', 'servicos', 'perfis', 'cfg'))->layout($this->layout);
    }
}
