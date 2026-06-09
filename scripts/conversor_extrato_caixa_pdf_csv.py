import csv
import re
import sys
from datetime import datetime

from PyPDF2 import PdfReader


if len(sys.argv) < 3:
    print("Uso: python conversor_extrato_caixa_pdf_csv.py <arquivo.pdf> <arquivo_saida.csv> [conta_banco]")
    sys.exit(1)

pdf_path = sys.argv[1]
csv_path = sys.argv[2]
conta_banco = sys.argv[3] if len(sys.argv) > 3 else "1.1.1.01"

reader = PdfReader(pdf_path)

# Exemplo de linha válida:
# 02/01/2026 021808 CRED PIX 275,00 C 7.903,23 C
PADRAO_LINHA = re.compile(
    r"^(\d{2}/\d{2}/\d{4})\s+(\d+)\s+(.+?)\s+([\d\.,]+)\s+([CD])\s+[\d\.,]+\s+[CD]$"
)

lancamentos = []

for page in reader.pages:
    texto = page.extract_text()
    if not texto:
        continue

    for linha_bruta in texto.split("\n"):
        linha = re.sub(r"\s+", " ", linha_bruta.strip())
        if not linha:
            continue

        match = PADRAO_LINHA.match(linha)
        if not match:
            continue

        data, numero_doc, historico, valor_str, natureza = match.groups()

        historico_upper = historico.upper()
        if "SALDO" in historico_upper:
            continue

        valor_float = float(valor_str.replace(".", "").replace(",", "."))
        if valor_float == 0:
            continue

        lancamentos.append(
            {
                "data": data,
                "numero_doc": numero_doc,
                "historico": historico.strip(),
                "valor": valor_float,
                "natureza": natureza,
            }
        )


def parse_data(data_str):
    try:
        return datetime.strptime(data_str, "%d/%m/%Y")
    except ValueError:
        return datetime(1900, 1, 1)


def formatar_valor_brl(valor):
    return f"{abs(valor):,.2f}".replace(".", "X").replace(",", ".").replace("X", ",")


lancamentos.sort(key=lambda item: parse_data(item["data"]))

with open(csv_path, "w", newline="", encoding="utf-8") as csvfile:
    writer = csv.writer(csvfile, delimiter=";")
    writer.writerow(
        [
            "Data do Lançamento",
            "Usuário",
            "Conta Débito",
            "Conta Crédito",
            "Valor do Lançamento",
            "Histórico",
            "Código da Filial/Matriz",
            "Nome da Empresa",
            "Número da Nota",
        ]
    )

    for item in lancamentos:
        if item["natureza"] == "C":
            conta_debito = conta_banco
            conta_credito = ""
            prefixo = "RCTO REF"
        else:
            conta_debito = ""
            conta_credito = conta_banco
            prefixo = "PGTO REF"

        historico_final = f"{prefixo} {item['historico']}".strip()

        writer.writerow(
            [
                item["data"],
                "Sistema",
                conta_debito,
                conta_credito,
                formatar_valor_brl(item["valor"]),
                historico_final,
                "",
                "",
                item["numero_doc"],
            ]
        )

print(f"CSV padronizado gerado em: {csv_path}")
print(f"Total de lançamentos processados: {len(lancamentos)}")
