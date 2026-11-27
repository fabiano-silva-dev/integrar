<?php

namespace App\Livewire\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\DpsLocal;
use App\Models\Nfse\FilaHomologacao as RegistroFila;
use App\Models\Nfse\Lote;
use App\Models\Nfse\TransmissaoRestrita;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\Dps\FilaEmissaoRestrita;
use App\Services\Nfse\PreparacaoNfse;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class FilaHomologacao extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    #[Locked]
    public ?int $empresaId = null;

    #[Locked]
    public array $selecionadas = [];

    public string $mes = '';

    public bool $confirmacao = false;

    public function mount(): void
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        if ($id = session('empresa_selecionada_id')) {
            $this->empresaId = app(AcessoEmissaoNfse::class)->empresa((int) $id)->id;
        }
        $this->mes = now()->format('Y-m');
    }

    public function updatedMes(): void
    {
        $this->selecionadas = [];
        $this->confirmacao = false;
        $this->resetPage();
    }

    public function selecionar(int $id): void
    {
        $empresa = $this->empresa();
        abort_unless($this->liberada($empresa) && $this->mesValido(), 403);
        $this->confirmacao = false;
        if (isset($this->selecionadas[$id])) {
            unset($this->selecionadas[$id]);

            return;
        }
        if (count($this->selecionadas) >= FilaEmissaoRestrita::LIMITE) {
            $this->addError('fila', 'Selecione até 20 DPS por fila.');

            return;
        }
        $dps = $this->candidatas($empresa)->findOrFail($id);
        $this->selecionadas[$id] = ['id' => $dps->id, 'hash' => $dps->xml_hash];
    }

    public function limpar(): void
    {
        $this->selecionadas = [];
        $this->confirmacao = false;
    }

    public function aprovar(): void
    {
        $empresa = $this->empresa();
        abort_unless($this->liberada($empresa), 403);
        $this->validate(['confirmacao' => 'accepted']);
        $fila = app(FilaEmissaoRestrita::class)->aprovar($empresa->id, $this->mes, array_values($this->selecionadas));
        $this->limpar();
        session()->flash('nfse_fila_mensagem', 'Fila #'.$fila->id.' registrada. Acompanhe o resultado de cada DPS; nenhum envio é repetido automaticamente.');
    }

    public function reenfileirar(int $id): void
    {
        $empresa = $this->empresa();
        abort_unless($this->liberada($empresa), 403);
        app(FilaEmissaoRestrita::class)->reenfileirarPendentes($empresa->id, $id);
        session()->flash('nfse_fila_mensagem', 'Solicitação de despacho dos itens ainda pendentes registrada.');
    }

    private function empresa(): Empresa
    {
        abort_unless($this->empresaId && (int) session('empresa_selecionada_id') === $this->empresaId, 403, 'Seleção da empresa mudou. Reabra a tela.');

        return app(AcessoEmissaoNfse::class)->empresa($this->empresaId);
    }

    private function mesValido(): bool
    {
        return (bool) preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->mes);
    }

    private function liberada(Empresa $empresa): bool
    {
        $doc = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $piloto = (string) config('automacao_fiscal.nfse_dps_restrita_prestador', '');

        return $piloto !== '' && hash_equals($piloto, $doc)
            && config('automacao_fiscal.nfse_dps_local_enabled', false)
            && config('automacao_fiscal.nfse_dps_restrita_enabled', false)
            && config('automacao_fiscal.nfse_dps_fila_enabled', false);
    }

    private function candidatas(Empresa $empresa)
    {
        return DpsLocal::daEmpresa($empresa)->where('ambiente', 2)->where('status', 'preparada_local')
            ->whereIn('lote_id', Lote::daEmpresa($empresa)->where('competencia', $this->mes.'-01')->where('status', 'preparado')->select('id'))
            ->whereNotIn('nota_id', TransmissaoRestrita::daEmpresa($empresa)
                ->where(fn ($q) => $q->whereNotNull('fila_homologacao_id')->orWhereNotNull('envio_iniciado_em')->orWhere('status', '!=', 'aprovada_teste'))->select('nota_id'));
    }

    public function render()
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $empresa = $this->empresaId ? $this->empresa() : null;
        $liberada = $empresa && $this->liberada($empresa);
        $mesValido = $this->mesValido();
        $candidatas = $filas = collect();
        $resultados = collect();
        if ($empresa && $mesValido) {
            $candidatas = $this->candidatas($empresa)->latest('id')->paginate(20, ['id', 'nota_id', 'numero', 'id_dps', 'xml_hash', 'versao_nota']);
            $filas = RegistroFila::daEmpresa($empresa)->where('competencia', $this->mes.'-01')->latest('id')->paginate(10, ['*'], 'filasPage');
            $resultados = TransmissaoRestrita::daEmpresa($empresa)->whereIn('fila_homologacao_id', $filas->pluck('id'))->orderBy('id')
                ->get(['id', 'fila_homologacao_id', 'dps_local_id', 'nota_id', 'status', 'fila_status', 'fila_iniciada_em', 'envio_iniciado_em'])
                ->groupBy('fila_homologacao_id');
        }

        return view('livewire.nfse.fila-homologacao', compact('empresa', 'liberada', 'mesValido', 'candidatas', 'filas', 'resultados'))->layout($this->layout);
    }
}
