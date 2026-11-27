<?php

namespace App\Http\Controllers\Nfse;

use App\Services\Nfse\Dps\ArtefatosHomologacao;
use App\Services\Nfse\Dps\DanfseHomologacao;
use Illuminate\Http\Response;
use RuntimeException;

class ArtefatoHomologacaoController
{
    public function dps(int $empresa, int $dps, ArtefatosHomologacao $artefatos): Response
    {
        try {
            [$registro, $xml] = $artefatos->dps($empresa, $dps);
        } catch (\Illuminate\Validation\ValidationException $e) {
            abort(404);
        } catch (RuntimeException $e) {
            // Exceções de autorização/404 não devem ser transformadas em erro de integridade.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }
            abort(422, 'Não foi possível validar a DPS de homologação.');
        }
        return $this->arquivo($xml, 'application/xml; charset=utf-8', 'HOMOLOGACAO-'.$registro->id_dps.'.xml');
    }

    public function nfse(int $empresa, int $transmissao, string $formato, ArtefatosHomologacao $artefatos, DanfseHomologacao $gerador): Response
    {
        abort_unless(in_array($formato, ['xml', 'pdf'], true), 404);
        try {
            [$r, $xml] = $artefatos->nfse($empresa, $transmissao);
        } catch (\Illuminate\Validation\ValidationException $e) {
            abort(404);
        } catch (RuntimeException $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw $e;
            }
            abort(422, 'Não foi possível validar o retorno de homologação.');
        }
        if ($formato === 'xml') {
            return $this->arquivo($xml, 'application/xml; charset=utf-8', 'HOMOLOGACAO-'.$r->chave_acesso.'.xml');
        }
        try {
            $pdf = $gerador->gerar($xml);
        } catch (\Throwable) {
            abort(422, 'Não foi possível gerar o DANFSe de homologação. O XML continua disponível.');
        }
        return $this->arquivo($pdf, 'application/pdf', 'HOMOLOGACAO-'.$r->chave_acesso.'-DANFSe.pdf');
    }

    private function arquivo(string $conteudo, string $mime, string $nome): Response
    {
        return response($conteudo, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$nome.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
