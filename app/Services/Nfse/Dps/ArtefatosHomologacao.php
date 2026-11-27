<?php

namespace App\Services\Nfse\Dps;

use App\Models\Empresa;
use App\Models\Nfse\DpsLocal;
use App\Models\Nfse\TransmissaoRestrita;
use App\Services\Nfse\AcessoEmissaoNfse;
use RuntimeException;

class ArtefatosHomologacao
{
    public function __construct(private AcessoEmissaoNfse $acessos) {}

    public function dps(int $empresaId, int $id): array
    {
        $empresa = $this->acessos->empresa($empresaId);
        $dps = DpsLocal::daEmpresa($empresa)->where('ambiente', 2)->findOrFail($id);
        return [$dps, $this->xmlDps($dps)];
    }

    public function nfse(int $empresaId, int $id): array
    {
        $empresa = $this->acessos->empresa($empresaId);
        $r = TransmissaoRestrita::daEmpresa($empresa)->where('status', 'confirmada_teste')->findOrFail($id);
        $dps = DpsLocal::daEmpresa($empresa)->where('ambiente', 2)->findOrFail($r->dps_local_id);
        $xml = $r->nfse_xml_criptografado;
        if (! is_string($xml) || ! is_string($r->nfse_xml_hash)
            || ! hash_equals($r->nfse_xml_hash, hash('sha256', $xml))
            || ! hash_equals($r->xml_hash, $dps->xml_hash)
            || (int) $r->nota_id !== (int) $dps->nota_id) {
            throw new RuntimeException('Integridade do retorno de homologação não confirmada.');
        }
        RetornoSefinRestrita::confirmar([
            'chaveAcesso' => $r->chave_acesso, 'idDps' => $dps->id_dps, 'tipoAmbiente' => 2,
            'nfseXmlGZipB64' => base64_encode(gzencode($xml)),
        ], $this->xmlDps($dps), $dps->id_dps);

        return [$r, $xml];
    }

    private function xmlDps(DpsLocal $dps): string
    {
        $xml = $dps->xml_criptografado;
        if (! is_string($xml) || ! hash_equals($dps->xml_hash, hash('sha256', $xml))
            || ! preg_match('/^DPS\d{42}$/D', $dps->id_dps)) {
            throw new RuntimeException('Integridade da DPS de homologação não confirmada.');
        }
        $xp = RetornoSefinRestrita::validarDps($xml);
        if ($xp->evaluate('string(/n:DPS/n:infDPS/@Id)') !== $dps->id_dps) {
            throw new RuntimeException('Identidade da DPS divergente.');
        }

        return $xml;
    }
}
