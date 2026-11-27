<?php

namespace App\Services\Nfse\Dps;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Núcleo experimental de geração local. Somente ambiente 2 e serviço nacional
 * tributado, sem retenções, benefícios, deduções ou grupo IBS/CBS.
 * Não atribui números oficiais nem determina enquadramento fiscal.
 */
class GeradorDps
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';
    public const VERSAO = '1.01';

    public function gerar(array $dados): DOMDocument
    {
        $prestador = $this->documento($dados['prestador'] ?? '');
        $tomador = $this->documento($dados['tomador'] ?? '');
        $municipio = $this->formato($dados['municipio_emissor'] ?? '', '/^[1-9]\d{6}$/D', 'Município emissor');
        $local = $this->formato($dados['municipio_prestacao'] ?? '', '/^[1-9]\d{6}$/D', 'Município da prestação');
        $serie = $this->formato($dados['serie'] ?? '', '/^[1-9]\d{0,4}$/D', 'Série');
        $numero = $this->formato($dados['numero'] ?? '', '/^[1-9]\d{0,14}$/D', 'Número de DPS de teste');
        $data = $this->formato($dados['competencia'] ?? '', '/^\d{4}-\d{2}-\d{2}$/D', 'Competência');
        $competencia = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        $emissao = $dados['emissao'] ?? null;
        if (! $competencia || $competencia->format('Y-m-d') !== $data || ! $emissao instanceof DateTimeImmutable
            || $data > $emissao->format('Y-m-d')) {
            throw new InvalidArgumentException('Data de competência ou emissão inválida.');
        }
        $regime = $this->formato($dados['opSimpNac'] ?? '', '/^[123]$/D', 'Opção pelo Simples Nacional');
        if (($dados['escopo_confirmado'] ?? false) !== true || ($dados['ibscbs_nao_exigido'] ?? false) !== true) {
            throw new InvalidArgumentException('Confirme expressamente o escopo fiscal limitado e a dispensa do grupo IBS/CBS para este teste.');
        }
        $apuracao = $dados['regApTribSN'] ?? null;
        if ($regime === '3') {
            $apuracao = $this->formato($apuracao, '/^[123]$/D', 'Regime de apuração do Simples');
        } elseif ($apuracao !== null && $apuracao !== '') {
            throw new InvalidArgumentException('Regime de apuração do Simples incompatível.');
        }

        $valor = $this->formato($dados['valor'] ?? '', '/^(?:0|[1-9]\d{0,12})\.\d{2}$/D', 'Valor do serviço');
        if ($valor === '0.00') {
            throw new InvalidArgumentException('Valor deve ser positivo.');
        }
        $tributacao = $this->formato($dados['codigo_tributacao_nacional'] ?? '', '/^\d{6}$/D', 'Código de tributação nacional');
        $nome = $this->texto($dados['nome_tomador'] ?? '', 150, 'Nome do tomador');
        $descricao = $this->texto($dados['descricao'] ?? '', 2000, 'Descrição do serviço');
        $id = self::id($municipio, $prestador, $serie, $numero);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $root = $doc->createElementNS(self::NS, 'DPS');
        $root->setAttribute('versao', self::VERSAO);
        $doc->appendChild($root);
        $inf = $this->no($root, 'infDPS');
        $inf->setAttribute('Id', $id);
        foreach (['tpAmb' => '2', 'dhEmi' => $emissao->format('Y-m-d\TH:i:sP'), 'verAplic' => 'IntegraExpert-0.1',
            'serie' => $serie, 'nDPS' => $numero, 'dCompet' => $data, 'tpEmit' => '1', 'cLocEmi' => $municipio] as $tag => $v) {
            $this->no($inf, $tag, $v);
        }
        $prest = $this->no($inf, 'prest');
        $this->no($prest, strlen($prestador) === 14 ? 'CNPJ' : 'CPF', $prestador);
        $this->opcional($prest, 'IM', $dados['im_prestador'] ?? null, '/^\d{1,15}$/D');
        $reg = $this->no($prest, 'regTrib');
        $this->no($reg, 'opSimpNac', $regime);
        if ($regime === '3') {
            $this->no($reg, 'regApTribSN', $apuracao);
        }
        $this->no($reg, 'regEspTrib', '0');

        $toma = $this->no($inf, 'toma');
        $this->no($toma, strlen($tomador) === 14 ? 'CNPJ' : 'CPF', $tomador);
        $this->opcional($toma, 'IM', $dados['im_tomador'] ?? null, '/^\d{1,15}$/D');
        $this->no($toma, 'xNome', $nome);
        $endereco = $dados['endereco_tomador'] ?? [];
        if ($endereco !== []) {
            if (! is_array($endereco)) {
                throw new InvalidArgumentException('Endereço inválido.');
            }
            $end = $this->no($toma, 'end');
            $nac = $this->no($end, 'endNac');
            $this->no($nac, 'cMun', $this->formato($endereco['municipio'] ?? '', '/^[1-9]\d{6}$/D', 'Município do tomador'));
            $this->no($nac, 'CEP', $this->formato($endereco['cep'] ?? '', '/^\d{8}$/D', 'CEP'));
            $this->no($end, 'xLgr', $this->texto($endereco['logradouro'] ?? '', 255, 'Logradouro'));
            $this->no($end, 'nro', $this->texto($endereco['numero'] ?? '', 60, 'Número'));
            if (filled($endereco['complemento'] ?? null)) {
                $this->no($end, 'xCpl', $this->texto($endereco['complemento'], 156, 'Complemento'));
            }
            $this->no($end, 'xBairro', $this->texto($endereco['bairro'] ?? '', 60, 'Bairro'));
        }
        $serv = $this->no($inf, 'serv');
        $this->no($this->no($serv, 'locPrest'), 'cLocPrestacao', $local);
        $cs = $this->no($serv, 'cServ');
        $this->no($cs, 'cTribNac', $tributacao);
        $this->opcional($cs, 'cTribMun', $dados['codigo_tributacao_municipal'] ?? null, '/^\d{3}$/D');
        $this->no($cs, 'xDescServ', $descricao);
        $this->opcional($cs, 'cNBS', $dados['nbs'] ?? null, '/^\d{9}$/D');
        $val = $this->no($inf, 'valores');
        $this->no($this->no($val, 'vServPrest'), 'vServ', $valor);
        $trib = $this->no($val, 'trib');
        $mun = $this->no($trib, 'tribMun');
        $this->no($mun, 'tribISSQN', '1');
        $this->no($mun, 'tpRetISSQN', '1');
        $tot = $this->no($trib, 'totTrib');
        // Percentuais informados explicitamente, nunca presumidos zero.
        $percentuais = $dados['percentuais_tributos'] ?? [];
        $pt = $this->no($tot, 'pTotTrib');
        foreach (['pTotTribFed', 'pTotTribEst', 'pTotTribMun'] as $tag) {
            $p = $this->formato($percentuais[$tag] ?? '', '/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', $tag);
            if ((float) $p > 100) {
                throw new InvalidArgumentException('Percentual de tributos acima de 100.');
            }
            $this->no($pt, $tag, $p);
        }
        return $doc;
    }

    public static function id(string $municipio, string $documento, string $serie, string $numero): string
    {
        if (! preg_match('/^[1-9]\d{6}$/D', $municipio) || ! preg_match('/^(?:\d{11}|\d{14})$/D', $documento)
            || ! preg_match('/^[1-9]\d{0,4}$/D', $serie) || ! preg_match('/^[1-9]\d{0,14}$/D', $numero)) {
            throw new InvalidArgumentException('Identificação da DPS inválida.');
        }
        return 'DPS'.$municipio.(strlen($documento) === 11 ? '1' : '2')
            .str_pad($documento, 14, '0', STR_PAD_LEFT).str_pad($serie, 5, '0', STR_PAD_LEFT).str_pad($numero, 15, '0', STR_PAD_LEFT);
    }

    private function documento(mixed $valor): string
    {
        return $this->formato($valor, '/^(?:\d{11}|\d{14})$/D', 'CPF/CNPJ');
    }

    private function formato(mixed $valor, string $regex, string $campo): string
    {
        if (! is_string($valor) || ! preg_match($regex, $valor)) {
            throw new InvalidArgumentException($campo.' inválido ou ausente.');
        }
        return $valor;
    }

    private function texto(mixed $valor, int $max, string $campo): string
    {
        if (! is_string($valor) || trim($valor) === '' || mb_strlen($valor) > $max || ! mb_check_encoding($valor, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $valor)) {
            throw new InvalidArgumentException($campo.' inválido ou ausente.');
        }
        return trim($valor);
    }

    private function no(DOMElement $pai, string $tag, ?string $valor = null): DOMElement
    {
        $doc = $pai->ownerDocument;
        $no = $doc->createElementNS(self::NS, $tag);
        if ($valor !== null) {
            $no->appendChild($doc->createTextNode($valor));
        }
        $pai->appendChild($no);
        return $no;
    }

    private function opcional(DOMElement $pai, string $tag, mixed $valor, string $regex): void
    {
        if ($valor !== null && $valor !== '') {
            $this->no($pai, $tag, $this->formato($valor, $regex, $tag));
        }
    }
}
