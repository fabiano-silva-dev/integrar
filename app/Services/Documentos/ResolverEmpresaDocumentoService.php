<?php

namespace App\Services\Documentos;

use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\GrupoWhatsapp;
use App\Models\Empresa;
use App\Rules\CnpjValido;
use Illuminate\Support\Collection;

class ResolverEmpresaDocumentoService
{
    /**
     * @param  Collection<int, Empresa>|iterable<Empresa>  $candidatas
     */
    public function resolver(DocumentoRecebido $documento, string $conteudo, iterable $candidatas): ?Empresa
    {
        $candidatas = collect($candidatas)->filter();

        if ($candidatas->count() === 1) {
            return $candidatas->first();
        }

        if ($candidatas->isEmpty()) {
            return null;
        }

        $cnpjsDocumento = $this->cnpjsDoDocumento($documento, $conteudo);

        $encontradas = $candidatas->filter(function (Empresa $empresa) use ($cnpjsDocumento) {
            $digits = CnpjValido::digits($empresa->cnpj);

            return $digits !== '' && in_array($digits, $cnpjsDocumento, true);
        });

        if ($encontradas->count() === 1) {
            return $encontradas->first();
        }

        return null;
    }

    /**
     * @return Collection<int, Empresa>
     */
    public function candidatasDoGrupo(?GrupoWhatsapp $grupo): Collection
    {
        if ($grupo === null) {
            return collect();
        }

        $ids = $grupo->idsEmpresas();

        if ($ids === []) {
            return collect();
        }

        return Empresa::withoutGlobalScope('operadora')
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * @return list<string>
     */
    public function cnpjsDoDocumento(DocumentoRecebido $documento, string $conteudo): array
    {
        $brutos = [];
        $meta = is_array($documento->metadados) ? $documento->metadados : [];

        foreach (['empresa_cnpj', 'cnpj_emitente', 'cnpj_destinatario'] as $chave) {
            if (! empty($meta[$chave]) && is_string($meta[$chave])) {
                $brutos[] = $meta[$chave];
            }
        }

        $chaveAcesso = preg_replace('/\D/', '', (string) ($meta['chave_acesso'] ?? '')) ?? '';

        if (strlen($chaveAcesso) === 44) {
            $brutos[] = substr($chaveAcesso, 6, 14);
        }

        $brutos = array_merge($brutos, $this->cnpjsDoXml($conteudo));

        $digits = [];
        foreach ($brutos as $valor) {
            $limpo = CnpjValido::digits((string) $valor);
            if (strlen($limpo) === 14) {
                $digits[] = $limpo;
            }
        }

        return array_values(array_unique($digits));
    }

    /**
     * @return list<string>
     */
    private function cnpjsDoXml(string $conteudo): array
    {
        $inicio = ltrim($conteudo);

        if ($inicio === '' || ($inicio[0] !== '<' && ! str_starts_with($inicio, '<?xml'))) {
            return [];
        }

        if (preg_match_all('/<cnpj>\s*(\d{14})\s*<\/cnpj>/i', $conteudo, $encontrados) === 0) {
            return [];
        }

        return array_values(array_unique($encontrados[1]));
    }
}
