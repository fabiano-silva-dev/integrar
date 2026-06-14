<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Importacao;
use App\Models\Empresa;
use App\Models\Lancamento;
use App\Rules\CnpjValido;
use App\Services\OperadoraStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ExportadorContabilController extends Controller
{
    public function getImportacoes()
    {
        $importacoes = Importacao::with('empresa')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($importacoes);
    }

    public function getEmpresas()
    {
        $empresas = Empresa::orderBy('nome')->get();
        return response()->json($empresas);
    }

    public function getImportacao($id)
    {
        $importacao = Importacao::with('empresa')->find($id);
        
        if (!$importacao) {
            return response()->json(['error' => 'Importação não encontrada'], 404);
        }

        return response()->json($importacao);
    }

    public function buscarEmpresaPorCodigo($codigo)
    {
        $empresa = Empresa::where('codigo_sistema', $codigo)->first();
        
        if (!$empresa) {
            return response()->json(null, 404);
        }

        return response()->json($empresa);
    }

    public function getDatasLancamentos($importacaoId)
    {
        $datas = Lancamento::where('importacao_id', $importacaoId)
            ->selectRaw('MIN(data) as data_min, MAX(data) as data_max')
            ->first();

        return response()->json([
            'data_min' => $datas->data_min ?? null,
            'data_max' => $datas->data_max ?? null
        ]);
    }

    public function getQuantidadeLancamentos(Request $request)
    {
        $query = Lancamento::query();
        
        if ($request->importacao_id) {
            $query->where('importacao_id', $request->importacao_id);
        } else {
            if ($request->data_inicio && $request->data_fim) {
                $query->whereBetween('data', [$request->data_inicio, $request->data_fim]);
            }
        }
        
        $quantidade = $query->count();
        
        return response()->json(['quantidade' => $quantidade]);
    }

    public function exportar(Request $request)
    {
        Log::info('Iniciando exportação via API', $request->all());

        // Validação
        $request->validate([
            'data_inicio' => 'required|date',
            'data_fim' => 'required|date|after_or_equal:data_inicio',
            'formato' => 'required|in:csv,txt',
            'layout_export' => 'required|in:padrao,contabil,simples,dominio'
        ]);

        // Validações específicas para layout Domínio
        if ($request->layout_export === 'dominio') {
            $request->merge([
                'cnpj_empresa' => CnpjValido::digits($request->cnpj_empresa ?? ''),
            ]);

            $request->validate([
                'codigo_empresa' => 'required|string|max:7',
                'cnpj_empresa' => ['required', 'string', 'size:14', new CnpjValido()],
                'tipo_nota' => 'required|in:01,02,03,04,05',
                'sistema' => 'required|in:0,1,2',
            ]);
        }

        try {
            // Buscar lançamentos
            $query = Lancamento::query();
            
            if ($request->importacao_id) {
                $query->where('importacao_id', $request->importacao_id);
            } else {
                $query->whereBetween('data', [$request->data_inicio, $request->data_fim]);
            }
            
            $lancamentos = $query->orderBy('data')->orderBy('id')->get();

            if ($lancamentos->isEmpty()) {
                return response()->json(['error' => 'Nenhum lançamento encontrado para o período selecionado.'], 400);
            }

            // Gerar conteúdo do arquivo
            $conteudo = $this->gerarConteudo($lancamentos, $request);
            
            // Converter para ISO-8859-1 se for layout Domínio
            if ($request->layout_export === 'dominio') {
                if (function_exists('mb_convert_encoding')) {
                    $conteudo = mb_convert_encoding($conteudo, 'ISO-8859-1', 'UTF-8');
                } else {
                    $conteudo = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $conteudo);
                }
            }
            
            $nomeArquivo = $this->gerarNomeArquivo($request);
            
            OperadoraStorage::put('exports', $nomeArquivo, $conteudo);
            
            Log::info('Exportação concluída com sucesso', [
                'arquivo' => $nomeArquivo,
                'lançamentos' => $lancamentos->count()
            ]);
            
            return response()->json([
                'arquivo' => $nomeArquivo,
                'quantidade_registros' => $lancamentos->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro na exportação via API', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['error' => 'Erro na exportação: ' . $e->getMessage()], 500);
        }
    }

    private function gerarConteudo($lancamentos, $request)
    {
        switch ($request->layout_export) {
            case 'contabil':
                return $this->gerarLayoutContabil($lancamentos, $request->formato);
            case 'simples':
                return $this->gerarLayoutSimples($lancamentos, $request->formato);
            case 'dominio':
                return $this->gerarLayoutDominio($lancamentos, $request);
            default:
                return $this->gerarLayoutPadrao($lancamentos, $request->formato);
        }
    }

    private function gerarLayoutPadrao($lancamentos, $formato)
    {
        $linhas = [];
        
        if ($formato === 'csv') {
            $linhas[] = 'Data;Histórico;Conta Débito;Conta Crédito;Valor;Terceiro';
        }

        foreach ($lancamentos as $lancamento) {
            if ($formato === 'csv') {
                $linhas[] = implode(';', [
                    $lancamento->data->format('d/m/Y'),
                    $lancamento->historico,
                    $lancamento->conta_debito ?? '',
                    $lancamento->conta_credito ?? '',
                    number_format($lancamento->valor, 2, ',', '.'),
                    $lancamento->nome_empresa ?? ''
                ]);
            } else {
                $linha = str_pad($lancamento->data->format('dmY'), 8);
                $linha .= str_pad(substr($lancamento->historico, 0, 50), 50);
                $linha .= str_pad($lancamento->conta_debito ?? '', 10);
                $linha .= str_pad($lancamento->conta_credito ?? '', 10);
                $linha .= str_pad(number_format($lancamento->valor, 2, '', ''), 15);
                $linha .= str_pad($lancamento->nome_empresa ?? '', 30);
                $linhas[] = $linha;
            }
        }

        return implode("\n", $linhas);
    }

    private function gerarLayoutContabil($lancamentos, $formato)
    {
        $linhas = [];
        
        if ($formato === 'csv') {
            $linhas[] = 'Data;Tipo;Histórico;Conta;Débito;Crédito;Terceiro';
        }

        foreach ($lancamentos as $lancamento) {
            if ($formato === 'csv') {
                // Linha de débito
                $linhas[] = implode(';', [
                    $lancamento->data->format('d/m/Y'),
                    'D',
                    $lancamento->historico,
                    $lancamento->conta_debito ?? '',
                    number_format($lancamento->valor, 2, ',', '.'),
                    '',
                    $lancamento->nome_empresa ?? ''
                ]);
                
                // Linha de crédito
                $linhas[] = implode(';', [
                    $lancamento->data->format('d/m/Y'),
                    'C',
                    $lancamento->historico,
                    $lancamento->conta_credito ?? '',
                    '',
                    number_format($lancamento->valor, 2, ',', '.'),
                    $lancamento->nome_empresa ?? ''
                ]);
            } else {
                // Formato TXT contábil
                $linha = str_pad($lancamento->data->format('dmY'), 8);
                $linha .= 'D';
                $linha .= str_pad(substr($lancamento->historico, 0, 40), 40);
                $linha .= str_pad($lancamento->conta_debito ?? '', 10);
                $linha .= str_pad(number_format($lancamento->valor, 2, '', ''), 15);
                $linha .= str_pad('', 15); // Crédito vazio
                $linha .= str_pad($lancamento->nome_empresa ?? '', 30);
                $linhas[] = $linha;
                
                $linha = str_pad($lancamento->data->format('dmY'), 8);
                $linha .= 'C';
                $linha .= str_pad(substr($lancamento->historico, 0, 40), 40);
                $linha .= str_pad($lancamento->conta_credito ?? '', 10);
                $linha .= str_pad('', 15); // Débito vazio
                $linha .= str_pad(number_format($lancamento->valor, 2, '', ''), 15);
                $linha .= str_pad($lancamento->nome_empresa ?? '', 30);
                $linhas[] = $linha;
            }
        }

        return implode("\n", $linhas);
    }

    private function gerarLayoutSimples($lancamentos, $formato)
    {
        $linhas = [];
        
        foreach ($lancamentos as $lancamento) {
            if ($formato === 'csv') {
                $linhas[] = implode(';', [
                    $lancamento->data->format('Y-m-d'),
                    $lancamento->historico,
                    $lancamento->valor
                ]);
            } else {
                $linha = $lancamento->data->format('Y-m-d') . ' | ';
                $linha .= $lancamento->historico . ' | ';
                $linha .= number_format($lancamento->valor, 2, ',', '.');
                $linhas[] = $linha;
            }
        }

        return implode("\n", $linhas);
    }

    private function gerarLayoutDominio($lancamentos, $request)
    {
        $linhas = [];
        $sequencial = 1;
        
        // Registro 01 - Cabeçalho do Arquivo (100 caracteres)
        $registro01 = '01';
        $registro01 .= str_pad($request->codigo_empresa, 7, '0', STR_PAD_LEFT);
        $cnpjLimpo = preg_replace('/[^0-9]/', '', $request->cnpj_empresa);
        $registro01 .= str_pad($cnpjLimpo, 14, ' ', STR_PAD_RIGHT);
        $registro01 .= $request->data_inicio ? date('d/m/Y', strtotime($request->data_inicio)) : str_pad('', 10);
        $registro01 .= $request->data_fim ? date('d/m/Y', strtotime($request->data_fim)) : str_pad('', 10);
        $registro01 .= 'N';
        $registro01 .= str_pad($request->tipo_nota, 2, '0', STR_PAD_LEFT);
        $registro01 .= '00000';
        $registro01 .= $request->sistema;
        $registro01 .= '17';
        $registro01 = str_pad($registro01, 100, ' ', STR_PAD_RIGHT);
        
        $linhas[] = $registro01;
        
        foreach ($lancamentos as $lancamento) {
            // Registro 02 - Dados do Lote (100 caracteres)
            $registro02 = '02';
            $registro02 .= str_pad($sequencial, 7, '0', STR_PAD_LEFT);
            $registro02 .= 'X';
            $registro02 .= $lancamento->data ? $lancamento->data->format('d/m/Y') : str_pad('', 10);
            $registro02 .= str_pad('INTEGRAR02', 30, ' ', STR_PAD_RIGHT);
            $registro02 = str_pad($registro02, 100, ' ', STR_PAD_RIGHT);
            $linhas[] = $registro02;

            // Registro 03 - Partidas dos Lançamentos Contábeis (664 caracteres cada)
            $registro03 = '03';
            $registro03 .= str_pad($sequencial, 7, '0', STR_PAD_LEFT);
            $registro03 .= str_pad($lancamento->conta_debito ?? '', 7, '0', STR_PAD_LEFT);
            $registro03 .= str_pad($lancamento->conta_credito ?? '', 7, '0', STR_PAD_LEFT);
            $registro03 .= str_pad(number_format($lancamento->valor, 2, '', ''), 15, '0', STR_PAD_LEFT);
            $registro03 .= str_pad('0000000', 7, '0', STR_PAD_LEFT);
            
            $historicoLimpo = str_replace(["\r", "\n", "\t"], ' ', $lancamento->historico ?? '');
            $historicoLimpo = preg_replace('/\s+/', ' ', $historicoLimpo);
            $historicoLimpo = trim($historicoLimpo);
            
            $historico = mb_substr($historicoLimpo, 0, 512);
            $historicoFormatado = $this->mb_str_pad($historico, 512, ' ', STR_PAD_RIGHT);
            
            $registro03 .= $historicoFormatado;
            $registro03 .= str_pad($lancamento->codigo_filial_matriz ?? '', 7, '0', STR_PAD_LEFT);
            $registro03 = str_pad($registro03, 664, ' ', STR_PAD_RIGHT);
            $linhas[] = $registro03;

            $sequencial++;
        }
        
        // Registro 99 - Finalizador do Arquivo (100 caracteres)
        $registro99 = '99';
        $registro99 = str_pad($registro99, 100, '0', STR_PAD_RIGHT);
        
        $linhas[] = $registro99;
        
        return implode("\n", $linhas);
    }

    private function gerarNomeArquivo($request)
    {
        $codigoSistema = $request->codigo_empresa;
        
        $mesAno = '';
        if ($request->data_inicio) {
            $data = \Carbon\Carbon::parse($request->data_inicio);
            $mesAno = $data->format('m-Y');
        } else {
            $mesAno = now()->format('m-Y');
        }
        
        $extensao = $request->formato;
        return "exportacao_dominio_{$codigoSistema}_{$mesAno}.{$extensao}";
    }

    private function mb_str_pad(string $input, int $pad_length, string $pad_string = ' ', int $pad_type = STR_PAD_RIGHT, string $encoding = 'UTF-8')
    {
        $length = mb_strlen($input, $encoding);
        $pad_needed = $pad_length - $length;
        if ($pad_needed <= 0) {
            return mb_substr($input, 0, $pad_length, $encoding);
        }
        switch ($pad_type) {
            case STR_PAD_LEFT:
                return str_repeat($pad_string, $pad_needed) . $input;
            case STR_PAD_BOTH:
                $left = floor($pad_needed / 2);
                $right = $pad_needed - $left;
                return str_repeat($pad_string, $left) . $input . str_repeat($pad_string, $right);
            case STR_PAD_RIGHT:
            default:
                return $input . str_repeat($pad_string, $pad_needed);
        }
    }
} 