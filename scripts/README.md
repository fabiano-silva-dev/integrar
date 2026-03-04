# Conversores Excel para CSV

Este diretório contém scripts Python para converter arquivos Excel (.xls, .xlsx) para CSV de forma inteligente.

## 🚀 Scripts Disponíveis

### 1. `conversor_excel_inteligente.py` - Conversor Completo
**Uso:** Para conversões manuais e análise detalhada
```bash
python conversor_excel_inteligente.py entrada.xlsx saida.csv
python conversor_excel_inteligente.py -d ";" entrada.xls saida.csv
python conversor_excel_inteligente.py -e "latin1" entrada.xlsx saida.csv
```

**Recursos:**
- ✅ Detecção automática de tipos de dados
- ✅ Conversão inteligente de datas para dd/mm/yyyy
- ✅ Suporte a múltiplos engines (openpyxl, xlrd)
- ✅ Logging detalhado
- ✅ Resumo completo da conversão
- ✅ Múltiplos delimitadores e encodings

### 2. `conversor_laravel.py` - Conversor Otimizado para Laravel
**Uso:** Para integração com Laravel via `exec()`
```bash
python conversor_laravel.py entrada.xlsx saida.csv
```

**Recursos:**
- ✅ Retorna resultado em JSON
- ✅ Otimizado para execução via PHP
- ✅ Tratamento de erros robusto
- ✅ Detecção automática de tipos
- ✅ Conversão automática de datas

## 📦 Instalação das Dependências

```bash
pip install -r requirements.txt
```

**Dependências:**
- `pandas>=1.5.0` - Manipulação de dados
- `openpyxl>=3.0.0` - Leitura de arquivos .xlsx
- `xlrd>=2.0.0` - Leitura de arquivos .xls antigos
- `numpy>=1.21.0` - Operações numéricas

## 🔧 Integração com Laravel

### Opção 1: Conversão Automática
```php
// No seu controller ou service
public function converterExcelParaCsv($arquivoExcel, $arquivoCsv)
{
    $comando = "python scripts/conversor_laravel.py " . 
               escapeshellarg($arquivoExcel) . " " . 
               escapeshellarg($arquivoCsv);
    
    $resultado = shell_exec($comando);
    $dados = json_decode($resultado, true);
    
    if ($dados['sucesso']) {
        // Usar o CSV convertido
        return $dados;
    } else {
        throw new Exception($dados['mensagem']);
    }
}
```

### Opção 2: Detecção de Tipos
```php
// Detectar tipos antes da importação
public function detectarTiposColunas($arquivoExcel)
{
    $comando = "python scripts/conversor_laravel.py " . 
               escapeshellarg($arquivoExcel) . " /tmp/temp.csv";
    
    $resultado = shell_exec($comando);
    $dados = json_decode($resultado, true);
    
    return $dados['tipos_detectados'];
}
```

## 📊 Exemplo de Saída

### Conversor Completo
```
============================================================
RESUMO DA CONVERSÃO
============================================================
Total de linhas: 10
Total de colunas: 7

TIPOS DETECTADOS:
  dt_emissao                     -> data
  nr_carta_frete                 -> numero
  vl_frete                       -> numero
  nm_pessoa                      -> texto
  dt_vencimento                  -> data
  cd_matriz                      -> texto
  vl_total                       -> numero

COLUNAS DE DATA: 2
  - dt_emissao
  - dt_vencimento

COLUNAS NUMÉRICAS: 3
  - nr_carta_frete
  - vl_frete
  - vl_total

COLUNAS DE TEXTO: 2
  - nm_pessoa
  - cd_matriz
============================================================
```

### Conversor Laravel (JSON)
```json
{
  "sucesso": true,
  "mensagem": "Conversão realizada com sucesso",
  "arquivo_saida": "saida.csv",
  "tipos_detectados": {
    "dt_emissao": "data",
    "nr_carta_frete": "numero",
    "vl_frete": "numero"
  },
  "resumo": {
    "linhas": 10,
    "colunas": 7,
    "colunas_data": 2,
    "colunas_numero": 3,
    "colunas_texto": 2
  }
}
```

## 🎯 Vantagens sobre PhpSpreadsheet

1. **Detecção Inteligente**: Detecta automaticamente tipos de dados
2. **Conversão de Datas**: Converte datas do Excel para formato legível
3. **Performance**: Muito mais rápido para arquivos grandes
4. **Robustez**: Melhor tratamento de formatos antigos (.xls)
5. **Flexibilidade**: Múltiplos engines de leitura
6. **Manutenibilidade**: Código mais limpo e focado

## 🧪 Testes

### Criar arquivo de teste
```bash
python criar_excel_teste.py
```

### Testar conversor completo
```bash
python conversor_excel_inteligente.py arquivo_teste.xlsx saida_teste.csv
```

### Testar conversor Laravel
```bash
python conversor_laravel.py arquivo_teste.xlsx saida_laravel.csv
```

## 🔍 Troubleshooting

### Arquivo .xls corrompido
- Use o conversor que tenta múltiplos engines
- Verifique se o arquivo não está corrompido
- Tente abrir no Excel e salvar como .xlsx

### Problemas de encoding
- Use o parâmetro `-e` para especificar encoding
- Padrão: UTF-8
- Alternativas: latin1, cp1252

### Problemas de delimitador
- Use o parâmetro `-d` para especificar delimitador
- Padrão: vírgula (,)
- Alternativas: ponto e vírgula (;), tab (\t)

## 📝 Notas

- Os scripts são compatíveis com Python 3.7+
- Para produção, considere usar um queue job para conversões grandes
- Sempre valide os arquivos CSV gerados antes da importação
- Mantenha as dependências atualizadas









