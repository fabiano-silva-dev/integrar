<?php

namespace App\Services\Nfse\Dps;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

class RetornoSefinRestrita
{
    public static function chaveValida(string $chave): bool
    {
        // Aceita posições alfanuméricas somente para consulta no endpoint restrito.
        // A futura alteração do formato oficial será homologada antes de produção.
        return (bool) preg_match('/^[A-Z0-9]{50}$/D', $chave);
    }

    public static function validarDps(string $xml): DOMXPath
    {
        $xp = self::xpath($xml, 1048576);
        if ($xp->query('/n:DPS/n:infDPS')->length !== 1
            || self::unico($xp, '/n:DPS/n:infDPS/n:tpAmb') !== '2'
            || $xp->query('/n:DPS/s:Signature')->length !== 1
            || $xp->query('//n:subst')->length !== 0) {
            throw new RuntimeException('DPS fora do escopo restrito.');
        }
        return $xp;
    }

    public static function json(array $resposta): array
    {
        $body = $resposta['body'] ?? '';
        if (! is_string($body) || strlen($body) > 12582912) {
            return [];
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : [];
    }

    public static function codigos(array $json): array
    {
        $codigos = [];
        foreach (array_slice(is_array($json['erros'] ?? null) ? $json['erros'] : [], 0, 20) as $erro) {
            $codigo = is_array($erro) ? ($erro['codigo'] ?? null) : null;
            if (is_string($codigo) && preg_match('/^[A-Z0-9_-]{1,20}$/D', $codigo)) {
                $codigos[] = $codigo;
            }
        }
        return array_values(array_unique($codigos));
    }

    /** Confirma apenas resposta coerente com o documento de teste enviado. */
    public static function confirmar(array $json, string $dpsXml, string $id, ?string $chaveConsultada = null): array
    {
        $chave = $json['chaveAcesso'] ?? $chaveConsultada;
        if (! is_string($chave) || ! self::chaveValida($chave)
            || ($chaveConsultada !== null && $chave !== $chaveConsultada)
            || (isset($json['idDps']) && $json['idDps'] !== $id)
            || (isset($json['tipoAmbiente']) && (string) $json['tipoAmbiente'] !== '2')) {
            throw new RuntimeException('Identidade da resposta não confirmada.');
        }
        $b64 = $json['nfseXmlGZipB64'] ?? null;
        if (! is_string($b64) || strlen($b64) > 12582912 || ! is_string($bin = base64_decode($b64, true))
            || ! str_starts_with($bin, "\x1f\x8b") || ! is_string($xml = self::descompactar($bin))) {
            throw new RuntimeException('XML retornado indisponível.');
        }
        $origem = self::validarDps($dpsXml);
        $retorno = self::xpath($xml, 8388608);
        $prefixo = '/n:NFSe/n:infNFSe';
        $inf = $prefixo.'/n:DPS/n:infDPS';
        if (self::unico($retorno, $prefixo.'/@Id') !== 'NFS'.$chave
            || self::unico($retorno, $prefixo.'/n:cStat') !== '100'
            || self::unico($retorno, $inf.'/@Id') !== $id
            || self::unico($origem, '/n:DPS/n:infDPS/@Id') !== $id
            || self::unico($retorno, $inf.'/n:tpAmb') !== '2') {
            throw new RuntimeException('Identidade ou situação da NFS-e divergente.');
        }
        $a = $origem->query('/n:DPS/n:infDPS')->item(0);
        $b = $retorno->query($inf)->item(0);
        // Comparação conservadora de todo o conteúdo da DPS, ignorando prefixos e indentação.
        if (! $a instanceof DOMElement || ! $b instanceof DOMElement || self::estrutura($a) !== self::estrutura($b)) {
            throw new RuntimeException('DPS retornada diverge do conteúdo aprovado.');
        }
        $docTag = $origem->query('/n:DPS/n:infDPS/n:prest/n:CNPJ')->length === 1 ? 'CNPJ' : 'CPF';
        if (self::unico($retorno, $prefixo.'/n:emit/n:'.$docTag) !== self::unico($origem, '/n:DPS/n:infDPS/n:prest/n:'.$docTag)) {
            throw new RuntimeException('Prestador retornado divergente.');
        }
        return ['chave' => $chave, 'xml' => $xml];
    }

    private static function descompactar(string $bin): string|false
    {
        // GZIP malformado é retorno não confirmado, não aviso PHP nem conteúdo de log.
        set_error_handler(static fn () => true, E_WARNING);
        try {
            return gzdecode($bin, 8388608);
        } finally {
            restore_error_handler();
        }
    }

    private static function estrutura(DOMElement $node): array
    {
        $attrs = [];
        foreach ($node->attributes as $attr) {
            $attrs[($attr->namespaceURI ?? '').':'.$attr->localName] = $attr->value;
        }
        ksort($attrs);
        $filhos = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $filhos[] = self::estrutura($child);
            }
        }
        return [$node->namespaceURI, $node->localName, $attrs, $filhos === [] ? $node->textContent : $filhos];
    }

    private static function xpath(string $xml, int $max): DOMXPath
    {
        $dom = new DOMDocument;
        if ($xml === '' || strlen($xml) > $max || stripos($xml, '<!DOCTYPE') !== false || ! @$dom->loadXML($xml, LIBXML_NONET) || $dom->doctype) {
            throw new RuntimeException('XML inválido.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('n', GeradorDps::NS);
        $xp->registerNamespace('s', AssinadorDps::NS);
        return $xp;
    }

    private static function unico(DOMXPath $xp, string $path): string
    {
        $n = $xp->query($path);
        if (! $n || $n->length !== 1 || $n->item(0)->nodeValue === '') {
            throw new RuntimeException('Campo XML ausente ou ambíguo.');
        }
        return $n->item(0)->nodeValue;
    }
}
