<?php

namespace App\Services\Nfse;

use App\Jobs\Nfse\ValidarAcessoPortalNfseJob;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\AcessoEmissao;
use App\Models\Nfse\ValidacaoPortal;
use App\Models\User;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ValidacaoPortalNfse
{
    public function __construct(private AcessoEmissaoNfse $acessos, private PortalWebNfseRunner $runner, private CertificadoDigitalService $certificados) {}

    public function solicitar(int $empresaId): ValidacaoPortal
    {
        $empresa = $this->acessos->empresa($empresaId);
        $limite = 'nfse-portal:'.$empresa->empresa_operadora_id;
        if (RateLimiter::tooManyAttempts($limite, 5)) {
            $this->falhar('Limite de testes atingido. Aguarde um minuto.');
        }
        RateLimiter::hit($limite, 60);
        return DB::transaction(function () use ($empresa) {
            $empresa = Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();
            [$a, $c] = $this->resolver($empresa, auth()->id());
            // Uma tentativa pendente por empresa, inclusive cliques concorrentes.
            $pendente = ValidacaoPortal::daEmpresa($empresa)->whereIn('status', ['pendente', 'iniciada'])->where('created_at', '>', now()->subMinutes(5))->first();
            if ($pendente) {
                return $pendente;
            }
            ValidacaoPortal::daEmpresa($empresa)->whereIn('status', ['pendente', 'iniciada'])
                ->update(['status' => 'nao_confirmada', 'codigo' => 'UNAVAILABLE', 'concluida_em' => now(), 'diagnostico_tecnico' => LogTesteAcessoNfse::json(['etapa' => 'worker_interrompido', 'codigo' => 'WORKER_INTERROMPIDO'])]);
            $a->update(['status_validacao' => 'pendente']);
            $r = ValidacaoPortal::create(['empresa_operadora_id' => $empresa->empresa_operadora_id, 'empresa_id' => $empresa->id,
                'acesso_id' => $a->id, 'usuario_id' => auth()->id(), 'modo' => $a->modo, 'config_hash' => $this->hash($a, $c), 'status' => 'pendente', 'diagnostico_tecnico' => ['etapa' => 'fila', 'codigo' => 'AGUARDANDO_WORKER']]);
            ValidarAcessoPortalNfseJob::dispatch((int) $empresa->empresa_operadora_id, $empresa->id, $r->id)->afterCommit();
            return $r;
        });
    }

    public function processar(int $operadoraId, int $empresaId, int $validacaoId): void
    {
        // O worker não utiliza auth/session nem contexto de uma execução anterior.
        $query = fn () => ValidacaoPortal::withoutGlobalScope('operadora')->where('empresa_operadora_id', $operadoraId)
            ->where('empresa_id', $empresaId)->whereKey($validacaoId);
        $r = $query()->first();
        if (! $r || $r->status !== 'pendente' || ! $query()->where('status', 'pendente')->update(['status' => 'iniciada', 'diagnostico_tecnico' => LogTesteAcessoNfse::json(['etapa' => 'validar_configuracao'])])) {
            return;
        }
        $inicio = hrtime(true);
        $log = ['etapa' => 'validar_configuracao'];
        $codigo = 'UNAVAILABLE';
        $status = 'nao_confirmada';
        try {
            $empresa = $this->empresaWorker($operadoraId, $empresaId);
            [$a, $c] = $this->resolver($empresa, $r->usuario_id);
            if ($r->created_at->lt(now()->subMinutes(5)) || ! hash_equals($r->config_hash, $this->hash($a, $c))) {
                $query()->update(['status' => 'config_alterada', 'concluida_em' => now(), 'diagnostico_tecnico' => LogTesteAcessoNfse::json(['etapa' => 'validar_configuracao', 'codigo' => 'CONFIG_ALTERADA', 'duracao_ms' => LogTesteAcessoNfse::duracao($inicio)])]);
                return;
            }
            $log = ['etapa' => 'preparar_credencial'];
            $query()->update(['diagnostico_tecnico' => LogTesteAcessoNfse::json($log)]);
            $entrada = ['mode' => $a->modo, 'companyDocument' => $a->documento_prestador,
                'loginDocument' => $c ? preg_replace('/\D/', '', (string) $c->documento_titular) : $a->usuario_criptografado];
            if ($c) {
                $pem = $this->certificados->extrairPem($c);
                // Detecta arquivo substituído sem atualização do cadastro.
                $fingerprint = openssl_x509_fingerprint($pem['cert'], 'sha256');
                if (! $fingerprint || strtolower(str_replace(':', '', $c->fingerprint)) !== strtolower(str_replace(':', '', $fingerprint))) {
                    $this->falhar('Certificado divergente do cadastro.');
                }
                $entrada['certificate'] = $pem;
            } else {
                $entrada['password'] = $a->senha_criptografada;
            }
            $log = ['etapa' => 'iniciar_runner'];
            $query()->update(['diagnostico_tecnico' => LogTesteAcessoNfse::json($log)]);
            $resultado = $this->runner->testar($entrada);
            unset($entrada, $pem);
            $codigo = in_array($resultado['code'] ?? '', PortalWebNfseRunner::CODIGOS, true) ? $resultado['code'] : 'UNAVAILABLE';
            $log = LogTesteAcessoNfse::filtrar(is_array($resultado['diagnostico'] ?? null) ? $resultado['diagnostico'] : []) + ['etapa' => 'validar_retorno', 'codigo' => $codigo];
            $log['identidade_confirmada'] = ($resultado['identityConfirmed'] ?? false) === true;
            $log['representacao_confirmada'] = ($resultado['representationConfirmed'] ?? false) === true;
            $representante = str_ends_with($a->modo, '_representante');
            if ($codigo === 'ACCESS_CONFIRMED' && ($resultado['identityConfirmed'] ?? false) === true
                && ($resultado['representationConfirmed'] ?? false) === $representante) {
                $status = 'confirmada';
            } elseif ($codigo === 'ACCESS_CONFIRMED') {
                $codigo = 'IDENTITY_MISMATCH';
                $log['codigo'] = $codigo;
            }
        } catch (ValidationException) {
            $status = 'config_alterada';
            $log['codigo'] = 'CONFIG_INVALIDA';
        } catch (\Throwable) {
            $log['codigo'] = $log['etapa'] === 'preparar_credencial' ? 'CREDENCIAL_INDISPONIVEL' : 'ERRO_TECNICO';
            // Não persiste exceções que possam carregar credenciais, HTML ou detalhes da sessão.
        }
        DB::transaction(function () use ($operadoraId, $empresaId, $r, $query, $codigo, $status, $log, $inicio) {
            try {
                $empresa = $this->empresaWorker($operadoraId, $empresaId, true);
                [$a, $c] = $this->resolver($empresa, $r->usuario_id);
                if (! hash_equals($r->config_hash, $this->hash($a, $c))) {
                    $status = 'config_alterada';
                }
                if ($status !== 'config_alterada') {
                    // Acesso ao portal não altera os bloqueios de transmissão/autorizações fiscais.
                    $a->update(['status_validacao' => $status === 'confirmada' ? 'portal_confirmado' : 'pendente']);
                }
            } catch (\Throwable) {
                $status = 'config_alterada';
            }
            if ($status === 'config_alterada' && ! in_array($log['codigo'] ?? '', ['CONFIG_INVALIDA', 'CONFIG_ALTERADA'], true)) {
                $log = ['etapa' => 'revalidar_configuracao', 'codigo' => 'CONFIG_ALTERADA'];
            }
            $log['duracao_ms'] = LogTesteAcessoNfse::duracao($inicio);
            $query()->where('status', 'iniciada')->update(['status' => $status, 'codigo' => $codigo, 'concluida_em' => now(), 'diagnostico_tecnico' => LogTesteAcessoNfse::json($log)]);
        });
    }

    private function empresaWorker(int $operadoraId, int $empresaId, bool $lock = false): Empresa
    {
        $q = Empresa::withoutGlobalScope('operadora')->where('empresa_operadora_id', $operadoraId)->where('ativo', true)->whereKey($empresaId);
        if ($lock) {
            $q->lockForUpdate();
        }
        return $q->firstOrFail();
    }

    private function resolver(Empresa $empresa, int $usuarioId): array
    {
        $u = User::find($usuarioId);
        if (! $u || ! $u->podeAcessarSistema() || ! ($u->isSuperAdmin() || $u->isEscritorioAdmin() || $u->isGerente())
            || (! $u->isSuperAdmin() && (int) $u->empresa_operadora_id !== (int) $empresa->empresa_operadora_id)
            || ! EmpresasOperadora::whereKey($empresa->empresa_operadora_id)->where('ativo', true)->exists()) {
            $this->falhar('Permissão para testar acesso indisponível.');
        }
        $a = AcessoEmissao::withoutGlobalScope('operadora')->daEmpresa($empresa)->first();
        if (! $a || $a->canal !== 'portal' || ! in_array($a->modo, AcessoEmissaoNfse::MODOS, true)
            || $a->documento_prestador !== preg_replace('/\D/', '', (string) $empresa->cnpj)
            || ($a->procuracao_ate && $a->procuracao_ate->toDateString() < now()->toDateString())) {
            $this->falhar('Salve o acesso ao Emissor Web com documento e vigência compatíveis.');
        }
        $c = null;
        $representante = str_ends_with($a->modo, '_representante');
        if (str_starts_with($a->modo, 'certificado_')) {
            $q = CertificadoDigital::withoutGlobalScope('operadora')->where('empresa_operadora_id', $empresa->empresa_operadora_id)
                ->where('ativo', true)->where('tipo', 'A1')->where('valido_de', '<=', now())->where('valido_ate', '>', now());
            $representante ? $q->whereNull('empresa_id') : $q->where('empresa_id', $empresa->id);
            $c = $q->find($a->certificado_digital_id);
            if (! $c || ! $c->fingerprint) {
                $this->falhar('Selecione um certificado A1 vigente e validado.');
            }
        } elseif (! $a->usuario_criptografado || ! $a->senha_criptografada) {
            $this->falhar('Informe usuário e senha do Emissor Web.');
        }
        $login = $c ? preg_replace('/\D/', '', (string) $c->documento_titular) : $a->usuario_criptografado;
        if (! preg_match('/^(?:\d{11}|\d{14})$/D', (string) $login) || ($representante === ($login === $a->documento_prestador))) {
            $this->falhar('O titular do acesso não corresponde à modalidade selecionada.');
        }
        return [$a, $c];
    }

    private function hash(AcessoEmissao $a, ?CertificadoDigital $c): string
    {
        return hash_hmac('sha256', json_encode([$a->only(['id', 'modo', 'canal', 'documento_prestador', 'certificado_digital_id', 'procuracao_ate']),
            $a->getRawOriginal('usuario_criptografado'), $a->getRawOriginal('senha_criptografada'),
            $c?->only(['id', 'empresa_id', 'fingerprint', 'documento_titular', 'arquivo_path', 'valido_de', 'valido_ate', 'ativo', 'updated_at']),
            $c?->getRawOriginal('senha_criptografada')], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['acesso' => $mensagem]);
    }
}
