<?php

namespace App\Services\Nfse;

use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\Nfse\AcessoEmissao;
use App\Models\Nfse\DiagnosticoAcesso;
use App\Services\AutomacaoFiscal\Sefaz\NfseSefinNacionalClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use DOMDocument;
use DOMXPath;

class DiagnosticoApiNfse
{
    public function __construct(private AcessoEmissaoNfse $acessos, private NfseSefinNacionalClient $client) {}

    public function executar(int $empresaId, string $ambiente, string $chave): DiagnosticoAcesso
    {
        $empresa = $this->acessos->empresa($empresaId);
        abort_unless(config('automacao_fiscal.nfse_diagnostico_api_enabled', false), 403, 'Diagnóstico externo desabilitado no ambiente.');
        Validator::make(['ambiente' => $ambiente, 'chave' => $chave], [
            'ambiente' => 'required|in:restrita,producao', 'chave' => ['required', 'regex:/^\d{50}$/D'],
        ])->validate();
        $limite = 'nfse-diagnostico:'.$empresa->empresa_operadora_id;
        if (RateLimiter::tooManyAttempts($limite, 10)) {
            $this->falhar('Limite de consultas atingido. Aguarde um minuto.');
        }
        RateLimiter::hit($limite, 60);

        [$acesso, $certificado, $hash, $registro] = DB::transaction(function () use ($empresa, $ambiente, $chave) {
            $empresa = $this->lockEmpresa($empresa);
            [$acesso, $certificado] = $this->resolver($empresa);
            $hash = $this->hashConfiguracao($acesso, $certificado);
            $registro = new DiagnosticoAcesso([
                'acesso_id' => $acesso->id, 'certificado_id' => $certificado->id, 'usuario_id' => auth()->id(),
                'ambiente' => $ambiente, 'config_hash' => $hash, 'chave_hash' => hash('sha256', $chave),
                'status' => 'iniciada',
            ]);
            $registro->empresa_id = $empresa->id;
            $registro->empresa_operadora_id = $empresa->empresa_operadora_id;
            $registro->save();
            return [$acesso, $certificado, $hash, $registro];
        });

        // Rede fora da transação; nenhum XML, chave ou detalhe do erro é exibido ou persistido.
        $status = 'nao_confirmada';
        try {
            $xml = $this->client->consultarXmlParaDiagnostico($chave, $certificado, $ambiente);
            $status = self::corresponde($xml, $chave, $acesso->documento_prestador, $ambiente) ? 'consulta_confirmada' : 'identidade_divergente';
        } catch (\Throwable) {
            // Falha de transporte, rejeição, ausência de documento ou resposta inválida não prova falta de procuração.
        }

        return DB::transaction(function () use ($empresa, $hash, $registro, $status) {
            $empresa = $this->lockEmpresa($empresa);
            try {
                [$atual, $certificado] = $this->resolver($empresa);
                if (! hash_equals($hash, $this->hashConfiguracao($atual, $certificado))) {
                    $status = 'config_alterada';
                }
            } catch (ValidationException) {
                $status = 'config_alterada';
            }
            $registro = DiagnosticoAcesso::daEmpresa($empresa)->lockForUpdate()->findOrFail($registro->id);
            $registro->update(['status' => $status]);
            return $registro;
        });
    }

    private function resolver(Empresa $empresa): array
    {
        $a = AcessoEmissao::daEmpresa($empresa)->first();
        if (! $a || $a->canal !== 'api' || ! in_array($a->modo, ['certificado_proprio', 'certificado_representante'], true)
            || $a->documento_prestador !== preg_replace('/\D/', '', (string) $empresa->cnpj)
            || ($a->procuracao_ate && $a->procuracao_ate->toDateString() < now()->toDateString())) {
            $this->falhar('Salve um acesso por certificado para API com documento e vigência compatíveis.');
        }
        $c = $this->acessos->certificados($empresa, $a->modo)->find($a->certificado_digital_id);
        if (! $c || ! $c->fingerprint || ($a->modo === 'certificado_proprio' && preg_replace('/\D/', '', (string) $c->documento_titular) !== $a->documento_prestador)) {
            $this->falhar('Certificado selecionado indisponível ou incompatível. Revise o cadastro.');
        }
        return [$a, $c];
    }

    private function lockEmpresa(Empresa $empresa): Empresa
    {
        $this->acessos->empresa($empresa->id);
        return Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)
            ->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
    }

    private function hashConfiguracao(AcessoEmissao $a, CertificadoDigital $c): string
    {
        return hash('sha256', json_encode([
            $a->only(['id', 'modo', 'canal', 'documento_prestador', 'certificado_digital_id', 'procuracao_ate', 'updated_at']),
            $c->only(['id', 'fingerprint', 'documento_titular', 'valido_de', 'valido_ate', 'ativo', 'updated_at']),
        ], JSON_THROW_ON_ERROR));
    }

    public static function corresponde(string $xml, string $chave, string $documento, string $ambiente): bool
    {
        if (strlen($xml) > 8388608 || stripos($xml, '<!DOCTYPE') !== false || ! preg_match('/^\d{50}$/D', $chave)
            || ! preg_match('/^(?:\d{11}|\d{14})$/D', $documento) || ! in_array($ambiente, ['restrita', 'producao'], true)) {
            return false;
        }
        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET) || $dom->doctype !== null) {
            return false;
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        $nodes = $xp->query('/n:NFSe/n:infNFSe');
        if (! $nodes || $nodes->length !== 1 || $nodes->item(0)->getAttribute('Id') !== 'NFS'.$chave) {
            return false;
        }
        $campo = strlen($documento) === 14 ? 'CNPJ' : 'CPF';
        $emit = $xp->query('/n:NFSe/n:infNFSe/n:emit/n:'.$campo);
        $prest = $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:prest/n:'.$campo);
        $tpAmb = $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:tpAmb');
        return $emit?->length === 1 && $prest?->length === 1 && $tpAmb?->length === 1
            && trim($emit->item(0)->textContent) === $documento
            && trim($prest->item(0)->textContent) === $documento
            && trim($tpAmb->item(0)->textContent) === ($ambiente === 'restrita' ? '2' : '1');
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['diagnostico' => $mensagem]);
    }
}
