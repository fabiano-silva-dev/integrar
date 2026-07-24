<?php

namespace App\Services\AutomacaoFiscal;

use Carbon\Carbon;
use InvalidArgumentException;

class ExtratoNfeEcacRsParser
{
    public const COLUNAS = [
        'dt_Emit',
        'Dt_Ent/Sai',
        'IE_Emit',
        'UF_Emit',
        'CNPJ_Emit',
        'Razao_Social_Emit',
        'IE_Dest/Remet',
        'UF_Dest/Remet',
        'CNPJ_Dest/Remet',
        'Razao_Social_Dest/Remet',
        'Mod',
        'Serie',
        'Numero',
        'Total_NF-e',
        'Total_BC_ICMS',
        'Total_ICMS',
        'Total_BC_ICMS_ST',
        'Total_ICMS_ST',
        'Sit',
        'E/S',
        'Chave_NF-e',
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
            throw new InvalidArgumentException('Arquivo de extrato vazio.');
        }

        $cabecalho = $this->splitLinha($linhas[0]);
        $avisos = [];

        if (!$this->cabecalhoCompativel($cabecalho)) {
            throw new InvalidArgumentException(
                'Cabeçalho incompatível com extrato NF-e do e-CAC RS. Esperado: ' . implode('; ', self::COLUNAS)
            );
        }

        $documentos = [];
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

        $resumo = $this->montarResumo($documentos);
        if (empty($resumo['cfop_disponivel'])) {
            $avisos[] = 'Extrato sem CFOP; a análise por CFOP não está disponível neste arquivo.';
        }
        if (($resumo['indicadores']['sem_base_icms'] ?? 0) > 0) {
            $avisos[] = 'Há documento(s) sem base de cálculo de ICMS no extrato.';
        }

        return [
            'documentos' => $documentos,
            'resumo' => $resumo,
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
    public function montarResumo(array $documentos, ?string $empresaCnpj = null): array
    {
        $totaisColunas = [
            'valor_total' => 0.0,
            'valor_bc_icms' => 0.0,
            'valor_icms' => 0.0,
            'valor_bc_icms_st' => 0.0,
            'valor_icms_st' => 0.0,
        ];

        $porSituacao = [];
        $porEntradaSaida = [];
        $porModelo = [];
        $porUfDestino = [];
        $porDia = [];
        $porCfop = [];
        $porTipoOperacao = [];

        $periodos = [];
        $semBc = 0;
        $comIcms = 0;
        $empresaDigits = self::somenteDigitos($empresaCnpj);

        foreach ($documentos as $doc) {
            foreach (array_keys($totaisColunas) as $campo) {
                $totaisColunas[$campo] += (float) $doc[$campo];
            }

            $this->acumular($porSituacao, (string) ($doc['situacao'] ?: '—'), $doc);
            $this->acumular($porEntradaSaida, (string) ($doc['entrada_saida'] ?: '—'), $doc);
            $this->acumular($porModelo, (string) ($doc['modelo'] ?: '—'), $doc);
            $this->acumular($porUfDestino, (string) ($doc['uf_destinatario'] ?: '—'), $doc);

            $dia = $doc['data_emissao'] ? Carbon::parse($doc['data_emissao'])->format('Y-m-d') : '—';
            $this->acumular($porDia, $dia, $doc);

            $tipoOp = $doc['tipo_operacao']
                ?? ($empresaDigits !== ''
                    ? self::classificarTipoOperacao($empresaDigits, $doc)
                    : null);
            if ($tipoOp) {
                $this->acumular($porTipoOperacao, $tipoOp, $doc);
            }

            $cfop = $doc['cfop'] ?? null;
            if ($cfop) {
                $this->acumular($porCfop, (string) $cfop, $doc);
            }

            if ((float) $doc['valor_bc_icms'] <= 0) {
                $semBc++;
            }
            if ((float) $doc['valor_icms'] > 0) {
                $comIcms++;
            }

            if (!empty($doc['data_emissao'])) {
                $periodos[] = $doc['data_emissao'];
            }
        }

        sort($periodos);

        $porTipoOrdenado = $this->ordenarGrupos($porTipoOperacao);
        // Garante as 4 operações na ordem fixa, mesmo com zero.
        $porTipoCompleto = [];
        foreach (array_keys(self::TIPOS_OPERACAO) as $codigo) {
            $encontrado = collect($porTipoOrdenado)->firstWhere('chave', $codigo);
            $porTipoCompleto[] = $encontrado ?? [
                'chave' => $codigo,
                'quantidade' => 0,
                'valor_total' => 0.0,
                'valor_icms' => 0.0,
                'valor_bc_icms' => 0.0,
            ];
        }

        return [
            'quantidade' => count($documentos),
            'periodo_inicio' => $periodos[0] ?? null,
            'periodo_fim' => $periodos === [] ? null : $periodos[array_key_last($periodos)],
            'totais_colunas' => array_map(fn ($v) => round($v, 2), $totaisColunas),
            'por_situacao' => $this->ordenarGrupos($porSituacao),
            'por_entrada_saida' => $this->ordenarGrupos($porEntradaSaida),
            'por_modelo' => $this->ordenarGrupos($porModelo),
            'por_uf_destino' => $this->ordenarGrupos($porUfDestino),
            'por_dia' => $this->ordenarGrupos($porDia, true),
            'por_cfop' => $this->ordenarGrupos($porCfop),
            'por_tipo_operacao' => $porTipoCompleto,
            'cfop_disponivel' => $porCfop !== [],
            'indicadores' => [
                'sem_base_icms' => $semBc,
                'com_icms' => $comIcms,
                'chaves_unicas' => count(array_unique(array_filter(array_column($documentos, 'chave_acesso')))),
            ],
            'emitente' => $documentos[0]['cnpj_emitente'] ?? null,
            'nome_emitente' => $documentos[0]['nome_emitente'] ?? null,
        ];
    }

    /**
     * @param  array<string, string>  $mapa
     * @return array<string, mixed>
     */
    private function normalizarLinha(array $mapa, int $linha): array
    {
        $emissao = $this->parseData($mapa['dt_Emit'] ?? null);
        $chave = AnaliseFiscalService::normalizarChaveAcesso($mapa['Chave_NF-e'] ?? null);

        return [
            'linha' => $linha,
            'tipo_documento' => (($mapa['Mod'] ?? '') === '65') ? 'nfce' : 'nfe',
            'chave_acesso' => $chave,
            'numero' => trim((string) ($mapa['Numero'] ?? '')) ?: null,
            'serie' => trim((string) ($mapa['Serie'] ?? '')) ?: null,
            'modelo' => trim((string) ($mapa['Mod'] ?? '')) ?: null,
            'data_emissao' => $emissao,
            'data_entrada_saida' => $this->parseData($mapa['Dt_Ent/Sai'] ?? null),
            'competencia' => $emissao ? substr($emissao, 0, 7) : null,
            'cnpj_emitente' => $this->formatCnpj($mapa['CNPJ_Emit'] ?? null),
            'nome_emitente' => trim((string) ($mapa['Razao_Social_Emit'] ?? '')) ?: null,
            'ie_emitente' => trim((string) ($mapa['IE_Emit'] ?? '')) ?: null,
            'uf_emitente' => strtoupper(trim((string) ($mapa['UF_Emit'] ?? ''))) ?: null,
            'cnpj_destinatario' => $this->formatCnpj($mapa['CNPJ_Dest/Remet'] ?? null),
            'nome_destinatario' => trim((string) ($mapa['Razao_Social_Dest/Remet'] ?? '')) ?: null,
            'ie_destinatario' => trim((string) ($mapa['IE_Dest/Remet'] ?? '')) ?: null,
            'uf_destinatario' => strtoupper(trim((string) ($mapa['UF_Dest/Remet'] ?? ''))) ?: null,
            'valor_total' => $this->parseMoney($mapa['Total_NF-e'] ?? '0'),
            'valor_bc_icms' => $this->parseMoney($mapa['Total_BC_ICMS'] ?? '0'),
            'valor_icms' => $this->parseMoney($mapa['Total_ICMS'] ?? '0'),
            'valor_bc_icms_st' => $this->parseMoney($mapa['Total_BC_ICMS_ST'] ?? '0'),
            'valor_icms_st' => $this->parseMoney($mapa['Total_ICMS_ST'] ?? '0'),
            'cfop' => null,
            'situacao' => trim((string) ($mapa['Sit'] ?? '')) ?: null,
            'entrada_saida' => trim((string) ($mapa['E/S'] ?? '')) ?: null,
            'origem' => 'ecac_rs_extrato_txt',
            'dados_complementares' => [
                'sit_label' => self::labelSituacao($mapa['Sit'] ?? null),
                'es_label' => self::labelEntradaSaida($mapa['E/S'] ?? null),
                'modelo_label' => self::labelModelo($mapa['Mod'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, array{chave: string, quantidade: int, valor_total: float, valor_icms: float}>  $grupo
     */
    private function acumular(array &$grupo, string $chave, array $doc): void
    {
        if (!isset($grupo[$chave])) {
            $grupo[$chave] = [
                'chave' => $chave,
                'quantidade' => 0,
                'valor_total' => 0.0,
                'valor_icms' => 0.0,
                'valor_bc_icms' => 0.0,
            ];
        }

        $grupo[$chave]['quantidade']++;
        $grupo[$chave]['valor_total'] += (float) $doc['valor_total'];
        $grupo[$chave]['valor_icms'] += (float) $doc['valor_icms'];
        $grupo[$chave]['valor_bc_icms'] += (float) $doc['valor_bc_icms'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $grupo
     * @return list<array<string, mixed>>
     */
    private function ordenarGrupos(array $grupo, bool $porChave = false): array
    {
        $lista = array_values($grupo);
        usort($lista, function ($a, $b) use ($porChave) {
            if ($porChave) {
                return strcmp((string) $a['chave'], (string) $b['chave']);
            }

            return $b['quantidade'] <=> $a['quantidade'];
        });

        return array_map(function ($item) {
            $item['valor_total'] = round((float) $item['valor_total'], 2);
            $item['valor_icms'] = round((float) $item['valor_icms'], 2);
            $item['valor_bc_icms'] = round((float) $item['valor_bc_icms'], 2);

            return $item;
        }, $lista);
    }

    /** @return list<string> */
    private function splitLinha(string $linha): array
    {
        $linha = rtrim($linha, "; \t");

        return array_map(static fn ($c) => trim($c), explode(';', $linha));
    }

    /** @param  list<string>  $cabecalho */
    private function cabecalhoCompativel(array $cabecalho): bool
    {
        $obrigatorias = ['dt_Emit', 'CNPJ_Emit', 'Numero', 'Total_NF-e', 'Chave_NF-e', 'Sit', 'E/S'];
        foreach ($obrigatorias as $col) {
            if (!$this->temColuna($cabecalho, $col)) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $cabecalho */
    private function temColuna(array $cabecalho, string $nome): bool
    {
        foreach ($cabecalho as $col) {
            if (strcasecmp($col, $nome) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $cols */
    private function linhaVazia(array $cols): bool
    {
        return implode('', $cols) === '';
    }

    private function parseMoney(?string $valor): float
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return 0.0;
        }

        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);

        return round((float) $valor, 2);
    }

    private function parseData(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        foreach (['d/m/y', 'd/m/Y'] as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $valor);

                return $dt->format('Y-m-d');
            } catch (\Throwable) {
                // tenta próximo
            }
        }

        return null;
    }

    private function formatCnpj(?string $valor): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $valor);
        if (!$digits) {
            return null;
        }

        if (strlen($digits) === 14) {
            return sprintf(
                '%s.%s.%s/%s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 4),
                substr($digits, 12, 2)
            );
        }

        return $digits;
    }

    /**
     * Ótica do portal (emitente) + ótica de análise da empresa (entre parênteses).
     *
     * - saida-consulente: vendas/saídas da empresa
     * - saida-terceiros: compras — saída do terceiro com a empresa como destinatária
     * - entrada-consulente: entradas emitidas pela própria empresa
     * - entrada-terceiros: contra-notas / devoluções (entrada no documento, emitente terceiro)
     */
    public const TIPOS_OPERACAO = [
        'saida-consulente' => 'Saídas emitidas pela empresa (Saídas Próprias)',
        'saida-terceiros' => 'Saídas emitidas por terceiros (Entradas de Terceiros)',
        'entrada-consulente' => 'Entradas emitidas pela empresa (Entradas Próprias)',
        'entrada-terceiros' => 'Entradas emitidas por terceiros (Contra Notas / Saídas Devolução)',
    ];

    public static function somenteDigitos(?string $valor): string
    {
        return preg_replace('/\D+/', '', (string) ($valor ?? '')) ?? '';
    }

    /**
     * Classifica a NF em relação à empresa consultada (mesmo critério do e-CAC).
     *
     * @param  array<string, mixed>  $doc
     */
    public static function classificarTipoOperacao(?string $empresaCnpj, array $doc): ?string
    {
        $empresa = self::somenteDigitos($empresaCnpj);
        if ($empresa === '') {
            return null;
        }

        $emit = self::somenteDigitos($doc['cnpj_emitente'] ?? null);
        $dest = self::somenteDigitos($doc['cnpj_destinatario'] ?? null);
        $es = strtoupper(trim((string) ($doc['entrada_saida'] ?? '')));

        if ($es === 'S') {
            if ($emit === $empresa) {
                return 'saida-consulente';
            }
            if ($dest === $empresa) {
                return 'saida-terceiros';
            }
        }

        if ($es === 'E') {
            if ($emit === $empresa) {
                return 'entrada-consulente';
            }
            if ($dest === $empresa) {
                return 'entrada-terceiros';
            }
        }

        return null;
    }

    public static function labelTipoOperacao(?string $codigo): string
    {
        $codigo = (string) $codigo;

        return self::TIPOS_OPERACAO[$codigo] ?? $codigo;
    }

    public static function labelSituacao(?string $sit): ?string
    {
        $sit = strtoupper(trim((string) $sit));
        if ($sit === '') {
            return null;
        }

        return match ($sit) {
            'N' => 'Normal',
            'C' => 'Cancelada',
            'I' => 'Inutilizada',
            'D' => 'Denegada',
            default => $sit,
        };
    }

    public static function labelEntradaSaida(?string $es): ?string
    {
        $es = strtoupper(trim((string) $es));
        if ($es === '') {
            return null;
        }

        return match ($es) {
            'S' => 'Saídas',
            'E' => 'Entradas',
            default => $es,
        };
    }

    public static function labelModelo(?string $modelo): ?string
    {
        $modelo = trim((string) $modelo);
        if ($modelo === '') {
            return null;
        }

        return match ($modelo) {
            '55' => 'NFe',
            '65' => 'NFC-e',
            default => $modelo,
        };
    }

    /**
     * Rótulo amigável para chaves de agrupamento (situação, E/S, modelo, operação).
     */
    public static function labelGrupo(string $tipo, mixed $chave): string
    {
        $chave = (string) $chave;

        return match ($tipo) {
            'por_situacao' => self::labelSituacao($chave) ?? $chave,
            'por_entrada_saida' => self::labelEntradaSaida($chave) ?? $chave,
            'por_modelo' => self::labelModelo($chave) ?? $chave,
            'por_tipo_operacao' => self::labelTipoOperacao($chave),
            default => $chave,
        };
    }
}
