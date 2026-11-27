<?php

namespace App\Livewire\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\AcessoEmissao;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\DpsLocal;
use App\Models\Nfse\DpsSequencia;
use App\Models\Nfse\Lote;
use App\Models\Nfse\Nota;
use App\Models\Nfse\TransmissaoRestrita;
use App\Models\Nfse\TransmissaoRestritaEvento;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\Dps\ArtefatosHomologacao;
use App\Services\Nfse\Dps\EmissaoRestrita;
use App\Services\Nfse\Dps\NumeracaoDpsHomologacao;
use App\Services\Nfse\Dps\RetornoSefinRestrita;
use App\Services\Nfse\Dps\ValidadorDps;
use App\Services\Nfse\PreparacaoNfse;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class Homologacao extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    #[Locked]
    public ?int $empresaId = null;
    #[Locked]
    public ?int $notaId = null;
    #[Locked]
    public ?int $dpsId = null;
    #[Locked]
    public string $hashRevisado = '';

    public string $mes = '';
    public string $ultimoNumero = '';
    public bool $confirmouSequencia = false;
    public bool $revisado = false;
    public bool $confirmouEnvio = false;
    public array $fiscal = [
        'municipio_prestacao' => '', 'regApTribSN' => null,
        'escopo_confirmado' => false, 'ibscbs_nao_exigido' => false,
        'percentuais_tributos' => ['pTotTribFed' => '', 'pTotTribEst' => '', 'pTotTribMun' => ''],
    ];

    public function mount(): void
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        if ($id = session('empresa_selecionada_id')) {
            $this->empresaId = app(AcessoEmissaoNfse::class)->empresa((int) $id)->id;
        }
        $this->mes = now()->format('Y-m');
        if ($this->empresaId && is_string($id = request()->query('dps')) && ctype_digit($id)) {
            $this->abrirDps((int) $id);
        }
    }

    public function updatedMes(): void
    {
        $this->notaId = $this->dpsId = null;
        $this->hashRevisado = '';
        $this->revisado = $this->confirmouEnvio = false;
        $this->resetPage('notasPage');
        $this->resetPage('dpsPage');
    }

    public function inicializar(): void
    {
        $this->validarLocal();
        $this->validate(['confirmouSequencia' => 'accepted']);
        app(NumeracaoDpsHomologacao::class)->inicializar($this->empresaId, trim($this->ultimoNumero));
        $this->ultimoNumero = '';
        $this->confirmouSequencia = false;
        $this->mensagem('Sequência local de homologação inicializada.');
    }

    public function selecionarNota(int $id): void
    {
        $empresa = $this->validarLocal();
        $nota = Nota::daEmpresa($empresa)->where('status', 'em_lote')->findOrFail($id);
        Lote::daEmpresa($empresa)->where('status', 'preparado')->findOrFail($nota->lote_id);
        $this->notaId = $nota->id;
        $this->dpsId = null;
        $this->hashRevisado = '';
        $this->revisado = $this->confirmouEnvio = false;
        $this->reset('fiscal');
        $this->resetValidation();
    }

    public function preparar(): void
    {
        $this->validarLocal();
        abort_unless($this->notaId, 422);
        try {
            $dps = app(NumeracaoDpsHomologacao::class)->preparar($this->empresaId, $this->notaId, $this->fiscal);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException|\InvalidArgumentException $e) {
            // Não mostrar detalhes de caminhos, arquivos, XML ou credenciais.
            $this->addError('homologacao', 'Não foi possível preparar a DPS. Revise cadastro, regras fiscais, certificado e XSD.');
            return;
        }
        $this->notaId = null;
        $this->abrirDps($dps->id);
        $this->mensagem('DPS local preparada. Revise o XML antes de aprovar.');
    }

    public function abrirDps(int $id): void
    {
        $empresa = $this->empresa();
        try {
            [$dps] = app(ArtefatosHomologacao::class)->dps($empresa->id, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->addError('homologacao', 'Não foi possível validar a integridade da DPS.');
            return;
        }
        $this->dpsId = $dps->id;
        $this->hashRevisado = $dps->xml_hash;
        $this->notaId = null;
        $this->revisado = $this->confirmouEnvio = false;
        $this->resetValidation();
    }

    public function aprovar(): void
    {
        $this->validarLocal(true);
        abort_unless($this->dpsId, 422);
        $this->validate(['revisado' => 'accepted']);
        app(EmissaoRestrita::class)->aprovar($this->empresaId, $this->dpsId, $this->hashRevisado);
        $this->revisado = false;
        $this->mensagem('Aprovação registrada exclusivamente para produção restrita.');
    }

    public function enviar(): void
    {
        $empresa = $this->validarLocal(true);
        $this->validate(['confirmouEnvio' => 'accepted']);
        $r = $this->transmissao($empresa);
        $this->confirmouEnvio = false;
        $r = app(EmissaoRestrita::class)->enviar($empresa->id, $r->id);
        $this->mensagem('Tentativa de homologação registrada: '.$this->rotulo($r->status).'.');
    }

    public function reconciliar(): void
    {
        $empresa = $this->validarLocal(true);
        $r = $this->transmissao($empresa);
        $r = app(EmissaoRestrita::class)->reconciliar($empresa->id, $r->id);
        $this->mensagem('Consulta registrada: '.$this->rotulo($r->status).'.');
    }

    public function rotulo(string $status): string
    {
        return match ($status) {
            'aprovada_teste' => 'Aprovada para teste',
            'envio_iniciado' => 'Envio iniciado — consultar após 90 s',
            'incerta' => 'Resultado incerto — não reenviar',
            'rejeitada_teste' => 'Rejeitada em teste',
            'confirmada_teste' => 'Confirmada em teste — sem valor fiscal',
            default => 'DPS local',
        };
    }

    private function empresa(): Empresa
    {
        abort_unless($this->empresaId && (int) session('empresa_selecionada_id') === $this->empresaId, 403, 'Seleção da empresa mudou. Reabra a tela.');
        return app(AcessoEmissaoNfse::class)->empresa($this->empresaId);
    }

    private function liberacao(Empresa $empresa): array
    {
        $doc = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $piloto = (string) config('automacao_fiscal.nfse_dps_restrita_prestador', '');
        $local = $piloto !== '' && hash_equals($piloto, $doc) && config('automacao_fiscal.nfse_dps_local_enabled', false);
        $rede = $local && config('automacao_fiscal.nfse_dps_restrita_enabled', false);
        return [(bool) $local, (bool) $rede];
    }

    private function validarLocal(bool $externo = false): Empresa
    {
        $empresa = $this->empresa();
        [$local, $rede] = $this->liberacao($empresa);
        abort_unless($externo ? $rede : $local, 403, 'Operação não liberada para este prestador piloto.');
        return $empresa;
    }

    private function transmissao(Empresa $empresa): TransmissaoRestrita
    {
        abort_unless($this->dpsId, 422);
        return TransmissaoRestrita::daEmpresa($empresa)->where('dps_local_id', $this->dpsId)->firstOrFail();
    }

    private function mensagem(string $msg): void
    {
        session()->flash('nfse_homologacao_mensagem', $msg);
    }

    public function render()
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $empresa = $this->empresaId ? $this->empresa() : null;
        $local = $rede = $xsd = false;
        $cfg = $acesso = $seq = $nota = $dps = $transmissao = null;
        $eventos = collect();
        $notas = $historico = collect();
        $resumo = [];
        $mesValido = (bool) preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->mes);
        if ($empresa) {
            [$local, $rede] = $this->liberacao($empresa);
            try {
                app(ValidadorDps::class)->garantirDisponivel();
                $xsd = true;
            } catch (RuntimeException) {}
            $cfg = Configuracao::daEmpresa($empresa)->first(['habilitado_preparo', 'regime_tributario', 'serie_dps']);
            $acesso = AcessoEmissao::daEmpresa($empresa)->first(['modo', 'canal', 'certificado_digital_id', 'procuracao_ate']);
            if ($cfg) {
                $seq = DpsSequencia::daEmpresa($empresa)->where('ambiente', 2)
                    ->where('documento_prestador', preg_replace('/\D/', '', (string) $empresa->cnpj))
                    ->where('serie', ltrim((string) $cfg->serie_dps, '0'))->first();
            }
            if ($mesValido) {
                $inicio = CarbonImmutable::createFromFormat('!Y-m', $this->mes);
                $notas = Nota::daEmpresa($empresa)->where('status', 'em_lote')
                    ->whereBetween('competencia', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()])
                    ->whereIn('lote_id', Lote::daEmpresa($empresa)->where('status', 'preparado')->select('id'))
                    ->latest('id')->paginate(15, ['*'], 'notasPage');
                $historico = DpsLocal::daEmpresa($empresa)->where('ambiente', 2)
                    ->whereIn('lote_id', Lote::daEmpresa($empresa)->where('competencia', $inicio->toDateString())->select('id'))
                    ->latest('id')->paginate(15, ['id', 'nota_id', 'id_dps', 'numero', 'versao_nota', 'created_at'], 'dpsPage');
            }
            if ($this->notaId) {
                $nota = Nota::daEmpresa($empresa)->where('status', 'em_lote')->find($this->notaId);
            }
            if ($this->dpsId) {
                [$dps, $xml] = app(ArtefatosHomologacao::class)->dps($empresa->id, $this->dpsId);
                $xp = RetornoSefinRestrita::validarDps($xml);
                $inf = '/n:DPS/n:infDPS';
                foreach (['Prestador' => '/n:prest/*[self::n:CNPJ or self::n:CPF]', 'Tomador' => '/n:toma/*[self::n:CNPJ or self::n:CPF]',
                    'Nome do tomador' => '/n:toma/n:xNome', 'Competência' => '/n:dCompet', 'Município de prestação' => '/n:serv/n:locPrest/n:cLocPrestacao',
                    'Código do serviço' => '/n:serv/n:cServ/n:cTribNac', 'Descrição' => '/n:serv/n:cServ/n:xDescServ', 'Valor' => '/n:valores/n:vServPrest/n:vServ',
                    'Opção Simples Nacional' => '/n:prest/n:regTrib/n:opSimpNac', 'Regime de apuração SN' => '/n:prest/n:regTrib/n:regApTribSN'] as $label => $path) {
                    $resumo[$label] = $xp->evaluate('string('.$inf.$path.')');
                }
                $transmissao = TransmissaoRestrita::daEmpresa($empresa)->where('dps_local_id', $dps->id)->first(['id', 'status', 'chave_acesso', 'envio_iniciado_em', 'ultima_consulta_em', 'fila_homologacao_id', 'fila_status']);
                if ($transmissao) {
                    $eventos = TransmissaoRestritaEvento::daEmpresa($empresa)->where('transmissao_id', $transmissao->id)->latest('id')->limit(30)->get();
                }
            }
        }
        return view('livewire.nfse.homologacao', compact('empresa', 'local', 'rede', 'xsd', 'cfg', 'acesso', 'seq', 'nota', 'dps', 'transmissao', 'eventos', 'notas', 'historico', 'mesValido', 'resumo'))->layout($this->layout);
    }
}
