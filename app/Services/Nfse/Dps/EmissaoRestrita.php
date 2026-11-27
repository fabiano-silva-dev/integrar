<?php

namespace App\Services\Nfse\Dps;

use App\Models\CertificadoDigital;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Prova técnica interna: autorização de teste nunca altera notas ou acervo fiscal de produção. */
class EmissaoRestrita
{
    public function __construct(private AcessoEmissaoNfse $acessos, private ValidadorDps $xsd, private TransporteSefinRestrita $transporte) {}

    public function aprovar(int $empresaId, int $dpsId, string $hashRevisado): TransmissaoRestrita
    {
        $empresa = $this->empresa($empresaId);
        return DB::transaction(function () use ($empresa, $dpsId, $hashRevisado) {
            $empresa = $this->lock($empresa);
            $dps = DpsLocal::daEmpresa($empresa)->where('ambiente', 2)->lockForUpdate()->findOrFail($dpsId);
            [$cert, $hash] = $this->contexto($empresa, $dps, true);
            if (! hash_equals($dps->xml_hash, $hashRevisado)) {
                $this->falhar('Confirme o hash do XML revisado para homologação.');
            }
            $anterior = TransmissaoRestrita::daEmpresa($empresa)->where('nota_id', $dps->nota_id)->lockForUpdate()->first();
            if ($anterior) {
                if ((int) $anterior->dps_local_id !== $dps->id || ! hash_equals($anterior->acesso_hash, $hash)) {
                    $this->falhar('Já existe aprovação para esta nota de teste. Não gere outra tentativa.');
                }
                return $anterior;
            }
            $r = new TransmissaoRestrita(['dps_local_id' => $dps->id, 'nota_id' => $dps->nota_id,
                'aprovado_por' => auth()->id(), 'status' => 'aprovada_teste', 'xml_hash' => $dps->xml_hash, 'acesso_hash' => $hash]);
            $r->empresa_id = $empresa->id;
            $r->empresa_operadora_id = $empresa->empresa_operadora_id;
            $r->save();
            $this->evento($empresa, $r, 'aprovar', 'aprovada_teste');
            return $r;
        });
    }

    public function enviar(int $empresaId, int $transmissaoId, ?int $filaId = null): TransmissaoRestrita
    {
        $empresa = $this->empresa($empresaId);
        [$r, $dps, $cert] = DB::transaction(function () use ($empresa, $transmissaoId, $filaId) {
            $empresa = $this->lock($empresa);
            $r = TransmissaoRestrita::daEmpresa($empresa)->lockForUpdate()->findOrFail($transmissaoId);
            if ($r->fila_homologacao_id !== null || $filaId !== null) {
                $fila = \App\Models\Nfse\FilaHomologacao::daEmpresa($empresa)->find($filaId);
                if (! $fila || (int) $r->fila_homologacao_id !== $filaId || $r->fila_status !== 'processando'
                    || (int) $fila->usuario_id !== auth()->id() || ! config('automacao_fiscal.nfse_dps_fila_enabled', false)) {
                    $this->falhar('Transmissão reservada à fila de homologação.');
                }
            }
            if ($r->status !== 'aprovada_teste' || $r->envio_iniciado_em !== null) {
                $this->falhar('A DPS já possui tentativa de envio. Utilize apenas reconciliação.');
            }
            $dps = DpsLocal::daEmpresa($empresa)->where('ambiente', 2)->lockForUpdate()->findOrFail($r->dps_local_id);
            [$cert, $hash] = $this->contexto($empresa, $dps, true);
            if (! hash_equals($r->acesso_hash, $hash) || ! hash_equals($r->xml_hash, $dps->xml_hash)) {
                $this->falhar('A configuração ou o XML aprovado mudou. Envio bloqueado.');
            }
            $r->update(['status' => 'envio_iniciado', 'envio_iniciado_em' => now()]);
            $this->evento($empresa, $r, 'enviar', 'envio_iniciado');
            return [$r, $dps, $cert];
        });
        // Estado já persistido; não há retry nem transação aberta durante a rede.
        $http = null;
        $codigos = [];
        $resultado = 'incerta';
        $confirmada = null;
        try {
            $res = $this->transporte->enviar($dps->xml_criptografado, $cert);
            $http = $res['http'];
            $json = RetornoSefinRestrita::json($res);
            $codigos = RetornoSefinRestrita::codigos($json);
            if ($http === 201) {
                $confirmada = RetornoSefinRestrita::confirmar($json, $dps->xml_criptografado, $dps->id_dps);
                $resultado = 'confirmada_teste';
            } elseif ($http === 400 && $codigos !== [] && ($json['idDps'] ?? null) === $dps->id_dps) {
                $resultado = 'rejeitada_teste';
            }
        } catch (\Throwable) {
            // A requisição pode ter sido aceita antes da falha. Nunca reenviar.
        }
        return $this->finalizar($empresa, $r->id, 'enviar', $resultado, $http, $codigos, $confirmada);
    }

    public function reconciliar(int $empresaId, int $transmissaoId): TransmissaoRestrita
    {
        $empresa = $this->empresa($empresaId);
        [$r, $dps, $cert] = DB::transaction(function () use ($empresa, $transmissaoId) {
            $empresa = $this->lock($empresa);
            $r = TransmissaoRestrita::daEmpresa($empresa)->lockForUpdate()->findOrFail($transmissaoId);
            if (! in_array($r->status, ['envio_iniciado', 'incerta', 'rejeitada_teste'], true)
                || ! $r->envio_iniciado_em || $r->envio_iniciado_em->gt(now()->subSeconds(90))
                || ($r->ultima_consulta_em && $r->ultima_consulta_em->gt(now()->subSeconds(90)))) {
                $this->falhar('Reconciliação indisponível. Aguarde 90 segundos entre operações.');
            }
            $dps = DpsLocal::daEmpresa($empresa)->where('ambiente', 2)->lockForUpdate()->findOrFail($r->dps_local_id);
            // Pode consultar mesmo se o lote foi desfeito; não pode reenviar.
            [$cert] = $this->contexto($empresa, $dps, false);
            if (! hash_equals($r->xml_hash, $dps->xml_hash)) {
                $this->falhar('XML de origem alterado.');
            }
            $r->update(['ultima_consulta_em' => now()]);
            $this->evento($empresa, $r, 'reconciliar', 'consulta_iniciada');
            return [$r, $dps, $cert];
        });
        $http = null;
        $codigos = [];
        $resultado = 'incerta';
        $confirmada = null;
        try {
            $res = $this->transporte->consultarDps($dps->id_dps, $cert);
            $http = $res['http'];
            $json = RetornoSefinRestrita::json($res);
            $codigos = RetornoSefinRestrita::codigos($json);
            $chave = $json['chaveAcesso'] ?? null;
            if ($http === 200 && is_string($chave) && RetornoSefinRestrita::chaveValida($chave)
                && (! isset($json['idDps']) || $json['idDps'] === $dps->id_dps)) {
                $res = $this->transporte->consultarNfse($chave, $cert);
                $http = $res['http'];
                if ($http === 200) {
                    $confirmada = RetornoSefinRestrita::confirmar(RetornoSefinRestrita::json($res), $dps->xml_criptografado, $dps->id_dps, $chave);
                    $resultado = 'confirmada_teste';
                }
            }
        } catch (\Throwable) {
            // 404, 403 ou falha de consulta não significam que a DPS pode ser reenviada.
        }
        return $this->finalizar($empresa, $r->id, 'reconciliar', $resultado, $http, $codigos, $confirmada);
    }

    private function finalizar(Empresa $empresa, int $id, string $acao, string $resultado, ?int $http, array $codigos, ?array $confirmada): TransmissaoRestrita
    {
        return DB::transaction(function () use ($empresa, $id, $acao, $resultado, $http, $codigos, $confirmada) {
            // Preserva o registro de uma tentativa iniciada mesmo se a empresa for inativada durante a rede.
            $r = TransmissaoRestrita::daEmpresa($empresa)->lockForUpdate()->findOrFail($id);
            if ($r->status !== 'confirmada_teste') {
                if ($confirmada) {
                    $r->fill(['status' => 'confirmada_teste', 'chave_acesso' => $confirmada['chave'],
                        'nfse_xml_criptografado' => $confirmada['xml'], 'nfse_xml_hash' => hash('sha256', $confirmada['xml'])]);
                } elseif ($r->status !== 'rejeitada_teste' || $resultado === 'rejeitada_teste') {
                    $r->status = $resultado;
                }
                $r->save();
            }
            $this->evento($empresa, $r, $acao, $resultado, $http, $codigos);
            return $r;
        });
    }

    private function contexto(Empresa $empresa, DpsLocal $dps, bool $paraEnvio): array
    {
        if ($dps->status !== 'preparada_local' || ! hash_equals($dps->xml_hash, hash('sha256', $dps->xml_criptografado))) {
            $this->falhar('Integridade da DPS não confirmada.');
        }
        $xp = RetornoSefinRestrita::validarDps($dps->xml_criptografado);
        $doc = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $seq = DpsSequencia::daEmpresa($empresa)->where('ambiente', 2)->lockForUpdate()->findOrFail($dps->sequencia_id);
        $tag = strlen($doc) === 14 ? 'CNPJ' : 'CPF';
        if ($seq->documento_prestador !== $doc || $xp->evaluate('string(/n:DPS/n:infDPS/n:prest/n:'.$tag.')') !== $doc
            || $xp->evaluate('string(/n:DPS/n:infDPS/@Id)') !== $dps->id_dps
            || $xp->evaluate('string(/n:DPS/n:infDPS/n:nDPS)') !== (string) $dps->numero) {
            $this->falhar('Identidade da DPS incompatível.');
        }
        $a = AcessoEmissao::daEmpresa($empresa)->lockForUpdate()->firstOrFail();
        if ($a->canal !== 'api' || ! in_array($a->modo, ['certificado_proprio', 'certificado_representante'], true)
            || $a->documento_prestador !== $doc || (int) $a->certificado_digital_id !== (int) $dps->certificado_id
            || ($a->procuracao_ate && $a->procuracao_ate->toDateString() < now()->toDateString())) {
            $this->falhar('Acesso incompatível com a DPS de teste.');
        }
        $cert = $this->acessos->certificados($empresa, $a->modo)->lockForUpdate()->findOrFail($dps->certificado_id);
        if (! $cert->fingerprint || ($a->modo === 'certificado_proprio' && preg_replace('/\D/', '', (string) $cert->documento_titular) !== $doc)) {
            $this->falhar('Certificado incompatível.');
        }
        $x509 = $xp->evaluate('string(/n:DPS/s:Signature/s:KeyInfo/s:X509Data/s:X509Certificate)');
        $fp = $x509 !== '' ? @openssl_x509_fingerprint("-----BEGIN CERTIFICATE-----\n".chunk_split($x509, 64, "\n")."-----END CERTIFICATE-----\n", 'sha256') : false;
        if (! is_string($fp) || ! hash_equals(strtolower($cert->fingerprint), strtolower($fp))) {
            $this->falhar('Certificado da assinatura não coincide com o configurado.');
        }
        $cfg = null;
        if ($paraEnvio) {
            $nota = Nota::daEmpresa($empresa)->where('status', 'em_lote')->lockForUpdate()->findOrFail($dps->nota_id);
            $lote = Lote::daEmpresa($empresa)->where('status', 'preparado')->lockForUpdate()->findOrFail($dps->lote_id);
            $cfg = Configuracao::daEmpresa($empresa)->where('habilitado_preparo', true)->lockForUpdate()->firstOrFail();
            $regime = match ($cfg->regime_tributario) {'normal' => '1', 'mei' => '2', 'simples_nacional' => '3', default => ''};
            if ((int) $nota->lote_id !== $lote->id || $nota->versao !== $dps->versao_nota
                || ltrim((string) $cfg->serie_dps, '0') !== $seq->serie
                || $xp->evaluate('string(/n:DPS/n:infDPS/n:prest/n:regTrib/n:opSimpNac)') !== $regime
                || $xp->evaluate('string(/n:DPS/n:infDPS/n:cLocEmi)') !== (string) $empresa->codigo_municipio_ibge) {
                $this->falhar('Lote ou configuração fiscal mudou desde a preparação.');
            }
            $perfis = app(\App\Services\Nfse\PerfisTributariosNfse::class);
            $perfil = $perfis->vigente($empresa, $nota);
            if ($perfil?->id !== ($dps->perfil_tributario_id === null ? null : (int) $dps->perfil_tributario_id)
                || ($perfil && $perfis->impedimentos($perfil, $cfg) !== [])) {
                $this->falhar('O perfil tributário vigente mudou ou está impedido. Revise a nota e a DPS.');
            }
            $this->xsd->validar($dps->xml_criptografado);
        }
        $hash = hash('sha256', json_encode([
            $a->only(['id', 'modo', 'canal', 'documento_prestador', 'certificado_digital_id', 'procuracao_ate']),
            $cert->only(['id', 'fingerprint', 'documento_titular', 'valido_de', 'valido_ate', 'updated_at']),
            $cfg?->only(['regime_tributario', 'serie_dps']), $dps->xml_hash,
        ], JSON_THROW_ON_ERROR));
        return [$cert, $hash];
    }

    private function empresa(int $id): Empresa
    {
        $empresa = $this->acessos->empresa($id);
        abort_unless(config('automacao_fiscal.nfse_dps_restrita_enabled', false)
            && config('automacao_fiscal.nfse_dps_local_enabled', false), 403, 'Homologação restrita desabilitada.');
        $doc = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $permitido = (string) config('automacao_fiscal.nfse_dps_restrita_prestador', '');
        abort_unless($permitido !== '' && hash_equals($permitido, $doc), 403, 'Prestador não liberado para o piloto restrito.');
        return $empresa;
    }

    private function lock(Empresa $empresa): Empresa
    {
        $this->empresa($empresa->id);
        return Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
    }

    private function evento(Empresa $empresa, TransmissaoRestrita $r, string $acao, string $resultado, ?int $http = null, array $codigos = []): void
    {
        $e = new TransmissaoRestritaEvento(['transmissao_id' => $r->id, 'usuario_id' => auth()->id(),
            'acao' => $acao, 'resultado' => $resultado, 'http' => $http, 'codigos' => $codigos]);
        $e->empresa_operadora_id = $empresa->empresa_operadora_id;
        $e->empresa_id = $empresa->id;
        $e->save();
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['emissao_restrita' => $mensagem]);
    }
}
