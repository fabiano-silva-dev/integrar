<?php

namespace App\Services\AutomacaoFiscal;

use Carbon\Carbon;
use InvalidArgumentException;

class ExtratoNfseParser
{
    public const COLUNAS = [
        'dt_Geracao',
        'Competencia',
        'CNPJ_Contraparte',
        'Nome_Contraparte',
        'Municipio_Emissor',
        'Valor_Servico',
        'Sit',
        'Sit_Label',
        'Tipo',
        'Numero',
        'Chave_NFS-e',
    ];

    /**
     * @return array{
     *   documentos: list<array<string, mixed>>,
     *   resumo: array<string, mixed>,
     *   avisos: list<string>
     * }
     */
    public function parse(string $conteudo): array
    {
        $conteudo = str_replace(["\r\n", "\r"], "\n", $conteudo);
        $linhas = array_values(array_filter(explode("\n", $conteudo), fn ($l) => trim($l) !== ''));

        if ($linhas === []) {
            throw new InvalidArgumentException('Arquivo de extrato NFS-e vazio.');
        }

        $cabecalho = $this->splitLinha($linhas[0]);
        if (!$this->cabecalhoCompativel($cabecalho)) {
            throw new InvalidArgumentException(
                'Cabeçalho incompatível com extratonfse.txt. Esperado: '.implode('; ', self::COLUNAS)
            );
        }

        $documentos = [];
        $avisos = [];
        foreach (array_slice($linhas, 1) as $numeroLinha => $linha) {
            $cols = $this->splitLinha($linha);
            if ($this->linhaVazia($cols)) {
                continue;
            }

            $mapa = [];
            foreach ($cabecalho as $i => $nome) {
                $mapa[$nome] = $cols[$i] ?? '';
            }

            $documentos[] = $this->normalizarLinha($mapa, $numeroLinha + 2);
        }

        return [
            'documentos' => $documentos,
            'resumo' => $this->montarResumo($documentos),
            'avisos' => $avisos,
        ];
    }

    public function parseArquivo(string $caminho): array
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) {
            throw new InvalidArgumentException('Não foi possível ler o arquivo.');
        }

        return $this->parse($conteudo);
    }

    /**
     * @param  list<array<string, mixed>>  $documentos
     * @return array<string, mixed>
     */
    public function montarResumo(array $documentos): array
    {
        $valorTotal = 0.0;
        $porSituacao = [];
        $porTipo = [];
        $porDia = [];
        $porMunicipio = [];
        $periodos = [];

        foreach ($documentos as $doc) {
            $valor = (float) ($doc['valor_total'] ?? 0);
            $valorTotal += $valor;

            $sit = (string) ($doc['situacao'] ?? '—');
            $this->acumular($porSituacao, $sit, $valor);

            $tipo = (string) (
                data_get($doc, 'dados_complementares.tipo_listagem')
                ?? $doc['entrada_saida']
                ?? '—'
            );
            $this->acumular($porTipo, $tipo, $valor);

            $municipio = (string) (data_get($doc, 'dados_complementares.municipio_emissor') ?: '—');
            $this->acumular($porMunicipio, $municipio, $valor);

            $dia = (string) ($doc['data_emissao'] ?? '');
            if ($dia !== '') {
                $this->acumular($porDia, $dia, $valor);
                $periodos[] = $dia;
            }
        }

        sort($periodos);

        return [
            'quantidade' => count($documentos),
            'valor_total' => round($valorTotal, 2),
            'totais_colunas' => [
                'valor_total' => round($valorTotal, 2),
            ],
            'por_situacao' => $this->ordenarGrupos($porSituacao),
            'por_tipo' => $this->ordenarGrupos($porTipo),
            'por_municipio_emissor' => $this->ordenarGrupos($porMunicipio),
            'por_dia' => $this->ordenarGrupos($porDia, true),
            'periodo_inicio' => $periodos[0] ?? null,
            'periodo_fim' => $periodos === [] ? null : $periodos[array_key_last($periodos)],
            'indicadores' => [
                'chaves_unicas' => count(array_unique(array_filter(array_column($documentos, 'chave_acesso')))),
            ],
            'emitente' => $documentos[0]['cnpj_emitente'] ?? null,
            'nome_emitente' => $documentos[0]['nome_emitente'] ?? null,
        ];
    }

    public static function labelTipoListagem(?string $tipo): string
    {
        return match ($tipo) {
            'emitidas' => 'NFS-e emitidas',
            'recebidas' => 'NFS-e recebidas',
            'S' => 'Serviços prestados (saída)',
            'E' => 'Serviços tomados (entrada)',
            default => $tipo ?: '—',
        };
    }

    public static function labelSituacao(?string $situacao): ?string
    {
        return match ($situacao) {
            'P100_GERADA' => 'NFS-e emitida',
            'P100_CANCELADA', 'CANCELADA' => 'Cancelada',
            'P100_SUBSTITUIDA', 'SUBSTITUIDA' => 'Substituída',
            default => $situacao,
        };
    }

    /**
     * @param  array<string, array{quantidade: int, valor_total: float}>  $grupo
     */
    private function acumular(array &$grupo, string $chave, float $valor): void
    {
        if (! isset($grupo[$chave])) {
            $grupo[$chave] = ['quantidade' => 0, 'valor_total' => 0.0];
        }
        $grupo[$chave]['quantidade']++;
        $grupo[$chave]['valor_total'] += $valor;
    }

    /**
     * @param  array<string, array{quantidade: int, valor_total: float}>  $grupo
     * @return list<array{chave: string, quantidade: int, valor_total: float}>
     */
    private function ordenarGrupos(array $grupo, bool $porChave = false): array
    {
        $lista = [];
        foreach ($grupo as $chave => $dados) {
            $lista[] = [
                'chave' => (string) $chave,
                'quantidade' => (int) $dados['quantidade'],
                'valor_total' => round((float) $dados['valor_total'], 2),
            ];
        }

        usort($lista, function (array $a, array $b) use ($porChave) {
            if ($porChave) {
                return strcmp($a['chave'], $b['chave']);
            }

            return $b['quantidade'] <=> $a['quantidade'];
        });

        return $lista;
    }

    /**
     * @param  array<string, string>  $mapa
     * @return array<string, mixed>
     */
    private function normalizarLinha(array $mapa, int $linha): array
    {
        $chave = AnaliseFiscalService::normalizarChaveAcesso($mapa['Chave_NFS-e'] ?? null);
        $tipo = strtolower(trim((string) ($mapa['Tipo'] ?? 'emitidas')));
        if (! in_array($tipo, ['emitidas', 'recebidas'], true)) {
            $tipo = 'emitidas';
        }

        $data = $this->parseData((string) ($mapa['dt_Geracao'] ?? ''));
        $competencia = $this->parseCompetencia((string) ($mapa['Competencia'] ?? ''), $data);
        $cnpjContraparte = $this->formatCnpj((string) ($mapa['CNPJ_Contraparte'] ?? ''));
        $municipio = trim((string) ($mapa['Municipio_Emissor'] ?? ''));
        $uf = null;
        if (preg_match('/\/([A-Z]{2})\s*$/', $municipio, $m)) {
            $uf = $m[1];
        }

        $numero = trim((string) ($mapa['Numero'] ?? ''));
        if ($numero === '' && $chave) {
            $numero = (string) (self::numeroFromChave($chave) ?? '');
        }

        // Emitidas: empresa = prestador (emitente); contraparte = tomador (destinatário).
        // Recebidas: contraparte = prestador (emitente); empresa = tomador.
        $entradaSaida = $tipo === 'recebidas' ? 'E' : 'S';

        return [
            'tipo_documento' => 'nfse',
            'chave_acesso' => $chave,
            'identificador_externo' => $chave,
            'numero' => $numero !== '' ? $numero : null,
            'serie' => null,
            'modelo' => null,
            'data_emissao' => $data,
            'data_entrada_saida' => $data,
            'competencia' => $competencia,
            'cnpj_emitente' => $tipo === 'recebidas' ? $cnpjContraparte : null,
            'nome_emitente' => $tipo === 'recebidas' ? trim((string) ($mapa['Nome_Contraparte'] ?? '')) : null,
            'ie_emitente' => null,
            'uf_emitente' => $tipo === 'recebidas' ? $uf : ($uf ?: null),
            'cnpj_destinatario' => $tipo === 'emitidas' ? $cnpjContraparte : null,
            'nome_destinatario' => $tipo === 'emitidas' ? trim((string) ($mapa['Nome_Contraparte'] ?? '')) : null,
            'ie_destinatario' => null,
            'uf_destinatario' => $tipo === 'emitidas' ? $uf : null,
            'valor_total' => $this->parseMoney((string) ($mapa['Valor_Servico'] ?? '')) ?? 0.0,
            'valor_bc_icms' => 0.0,
            'valor_icms' => 0.0,
            'valor_bc_icms_st' => 0.0,
            'valor_icms_st' => 0.0,
            'cfop' => null,
            'situacao' => trim((string) ($mapa['Sit'] ?? '')) ?: null,
            'entrada_saida' => $entradaSaida,
            'origem' => 'nfse_nacional_extrato_txt',
            'dados_complementares' => [
                'linha' => $linha,
                'tipo_listagem' => $tipo,
                'municipio_emissor' => $municipio !== '' ? $municipio : null,
                'sit_label' => trim((string) ($mapa['Sit_Label'] ?? '')) ?: null,
            ],
        ];
    }

    public static function numeroFromChave(string $chave): ?int
    {
        $digits = preg_replace('/\D+/', '', $chave) ?? '';
        if (strlen($digits) < 38) {
            return null;
        }

        // mun(7) + prefixo(2) + CNPJ(14) + número (padding) + complemento(14)
        $raw = substr($digits, 23, -14);
        $n = (int) $raw;

        return $n > 0 ? $n : null;
    }

    /**
     * Completa CNPJ emitente/destinatário com o da empresa quando a listagem não traz.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function completarComEmpresa(array $doc, ?string $empresaCnpj, ?string $empresaNome = null): array
    {
        $cnpj = self::formatCnpjStatic($empresaCnpj);
        $tipo = (string) ($doc['dados_complementares']['tipo_listagem'] ?? 'emitidas');

        if ($tipo === 'emitidas') {
            $doc['cnpj_emitente'] = $doc['cnpj_emitente'] ?: $cnpj;
            $doc['nome_emitente'] = $doc['nome_emitente'] ?: $empresaNome;
        } else {
            $doc['cnpj_destinatario'] = $doc['cnpj_destinatario'] ?: $cnpj;
            $doc['nome_destinatario'] = $doc['nome_destinatario'] ?: $empresaNome;
        }

        return $doc;
    }

    /**
     * @param  list<string>  $cabecalho
     */
    private function cabecalhoCompativel(array $cabecalho): bool
    {
        $norm = array_map(fn ($c) => mb_strtolower(trim($c)), $cabecalho);
        $obrigatorias = ['dt_geracao', 'chave_nfs-e', 'valor_servico'];

        foreach ($obrigatorias as $col) {
            if (! in_array($col, $norm, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitLinha(string $linha): array
    {
        $linha = rtrim($linha, "; \t");

        return array_map(static fn ($c) => trim($c), explode(';', $linha));
    }

    /**
     * @param  list<string>  $cols
     */
    private function linhaVazia(array $cols): bool
    {
        return implode('', $cols) === '';
    }

    private function parseData(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        // Portal Nacional: "30/06/26 10:08" ou "30/06/2026"
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2,4})(?:\s+\d{1,2}:\d{2})?/', $valor, $m)) {
            $ano = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];
            $valor = $m[1].'/'.$m[2].'/'.$ano;
        }

        foreach (['d/m/Y', 'd/m/y', 'Y-m-d'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $valor)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function parseCompetencia(string $valor, ?string $dataEmissao): ?string
    {
        $valor = trim($valor);
        if (preg_match('/^(\d{2})\/(\d{4})$/', $valor, $m)) {
            return $m[2].'-'.$m[1];
        }
        if (preg_match('/^\d{4}-\d{2}$/', $valor)) {
            return $valor;
        }
        if ($dataEmissao) {
            return substr($dataEmissao, 0, 7);
        }

        return null;
    }

    private function parseMoney(string $valor): ?float
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $normalizado = str_replace('.', '', $valor);
        $normalizado = str_replace(',', '.', $normalizado);
        if (! is_numeric($normalizado)) {
            return null;
        }

        return round((float) $normalizado, 2);
    }

    private function formatCnpj(string $valor): ?string
    {
        return self::formatCnpjStatic($valor);
    }

    private static function formatCnpjStatic(?string $valor): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $valor) ?? '';
        if (strlen($digits) !== 14) {
            return $digits !== '' ? $digits : null;
        }

        return substr($digits, 0, 2).'.'
            .substr($digits, 2, 3).'.'
            .substr($digits, 5, 3).'/'
            .substr($digits, 8, 4).'-'
            .substr($digits, 12, 2);
    }
}
