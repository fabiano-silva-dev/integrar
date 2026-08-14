#!/usr/bin/env python3
"""Gera PDFs sintéticos para testes do extrator de tabelas (sem dados de cliente)."""

from __future__ import annotations

import os


def _esc(texto: str) -> str:
    return texto.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def _pagina(comandos: list[str], width: float = 595, height: float = 842) -> str:
    stream = "\n".join(comandos)
    return stream, width, height


def escrever_pdf(caminho: str, paginas_cmds: list[list[str]], width: float = 595, height: float = 842) -> None:
    objects = []
    objects.append("<< /Type /Catalog /Pages 2 0 R >>")
    kids = " ".join(f"{3 + i} 0 R" for i in range(len(paginas_cmds)))
    objects.append(f"<< /Type /Pages /Kids [{kids}] /Count {len(paginas_cmds)} >>")

    content_ids = []
    page_ids = []
    streams = []
    for i, cmds in enumerate(paginas_cmds):
        page_id = 3 + i
        page_ids.append(page_id)
        stream = "\n".join(cmds).encode("latin-1", errors="replace")
        streams.append(stream)

    font_id = 3 + len(paginas_cmds) * 2
    # page objects then content objects then font
    # We'll build sequentially: catalog=1 pages=2 pages... contents... font
    # Simpler: catalog, pages, font, then pairs of page+content

    chunks = []
    chunks.append(b"%PDF-1.4\n")
    offsets = [0]

    def add_obj(num: int, body: bytes) -> None:
        offsets.append(len(b"".join(chunks)))
        chunks.append(f"{num} 0 obj\n".encode("ascii") + body + b"\nendobj\n")

    n_pages = len(paginas_cmds)
    font_num = 3 + n_pages * 2
    add_obj(1, b"<< /Type /Catalog /Pages 2 0 R >>")
    kids_str = " ".join(f"{3 + i * 2} 0 R" for i in range(n_pages))
    add_obj(2, f"<< /Type /Pages /Kids [{kids_str}] /Count {n_pages} >>".encode("ascii"))

    for i, cmds in enumerate(paginas_cmds):
        page_num = 3 + i * 2
        content_num = page_num + 1
        stream = ("\n".join(cmds) + "\n").encode("latin-1", errors="replace")
        add_obj(
            page_num,
            (
                f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {width} {height}] "
                f"/Contents {content_num} 0 R /Resources << /Font << /F1 {font_num} 0 R >> >> >>"
            ).encode("ascii"),
        )
        add_obj(
            content_num,
            f"<< /Length {len(stream)} >>\nstream\n".encode("ascii") + stream + b"endstream",
        )

    add_obj(font_num, b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")

    xref_pos = len(b"".join(chunks))
    n_objs = font_num
    xref = [b"xref\n", f"0 {n_objs + 1}\n".encode("ascii"), b"0000000000 65535 f \n"]
    # rebuild offsets properly by re-walking... easier rewrite below
    pdf = b"".join(chunks)
    # The offsets list is 1-based matching object numbers if we recorded at add time.
    xref_lines = ["xref", f"0 {n_objs + 1}", "0000000000 65535 f "]
    for obj_num in range(1, n_objs + 1):
        xref_lines.append(f"{offsets[obj_num]:010d} 00000 n ")
    xref_bin = ("\n".join(xref_lines) + "\n").encode("ascii")
    trailer = (
        f"trailer\n<< /Size {n_objs + 1} /Root 1 0 R >>\nstartxref\n{len(pdf)}\n%%EOF\n"
    ).encode("ascii")
    with open(caminho, "wb") as fh:
        fh.write(pdf + xref_bin + trailer)


def texto(x: float, y: float, s: str, size: float = 10) -> str:
    return f"BT /F1 {size} Tf {x:.1f} {y:.1f} Td ({_esc(s)}) Tj ET"


def rect(x: float, y: float, w: float, h: float) -> str:
    return f"0.6 0.6 0.6 RG 0.6 w {x:.1f} {y:.1f} {w:.1f} {h:.1f} re S"


def gerar_sem_bordas(caminho: str) -> None:
    cmds = [
        texto(40, 800, "Relatorio de Vendas - Formas de Recebimento", 12),
        texto(40, 780, "Unidade 1 - EMPRESA TESTE LTDA", 9),
        texto(40, 720, "Forma de Pagamento", 10),
        texto(320, 720, "Valor Recebido", 10),
        texto(480, 720, "%", 10),
        texto(40, 700, "A PRAZO"),
        texto(340, 700, "9.839,01"),
        texto(475, 700, "25,37"),
        texto(40, 682, "DINHEIRO"),
        texto(340, 682, "9.738,31"),
        texto(475, 682, "25,12"),
        texto(40, 664, "CARTAO DEBITO"),
        texto(340, 664, "5.469,51"),
        texto(475, 664, "14,11"),
        texto(40, 646, "PIX"),
        texto(340, 646, "4.205,05"),
        texto(475, 646, "10,84"),
        texto(40, 628, "CARTAO CREDITO"),
        texto(340, 628, "3.891,51"),
        texto(475, 628, "10,04"),
        texto(250, 604, "Total:"),
        texto(340, 604, "33.143,39"),
        texto(500, 40, "Pagina 1 de 1", 8),
    ]
    escrever_pdf(caminho, [cmds])


def gerar_sem_texto(caminho: str) -> None:
    escrever_pdf(caminho, [[rect(40, 700, 500, 80)]])


def gerar_html_multipagina(caminho: str) -> None:
    colunas = [
        (36, 95, "Operacao"),
        (131, 80, "Situacao"),
        (211, 170, "Pagador/Recebedor"),
        (381, 70, "CPF/CNPJ"),
        (451, 75, "Data"),
        (526, 60, "Valor"),
    ]
    xs = [c[0] for c in colunas] + [590]
    header_y = 760
    row_h = 22

    def pagina(linhas: list[list[str]], com_titulo: bool) -> list[str]:
        cmds = []
        if com_titulo:
            cmds.append(texto(40, 810, "Banco Teste - Operacoes Pix", 12))
        y = header_y
        for i, (x, w, titulo) in enumerate(colunas):
            cmds.append(rect(x, y, xs[i + 1] - x, row_h))
            cmds.append(texto(x + 4, y + 7, titulo, 9))
        y -= row_h
        for row in linhas:
            for i, valor in enumerate(row):
                x = colunas[i][0]
                w = xs[i + 1] - x
                cmds.append(rect(x, y, w, row_h))
                cmds.append(texto(x + 4, y + 7, valor, 9))
            y -= row_h
        return cmds

    p1 = [
        ["Pix Enviado", "Efetivado", "para ANA SOUZA", "-", "30/05/2026", "R$ 350,00"],
        ["Pix Enviado", "Efetivado", "para JOAO SILVA", "-", "29/05/2026", "R$ 210,00"],
        ["Pix Enviado", "Efetivado", "para MERCADO CENTRAL", "-", "28/05/2026", "R$ 745,91"],
        ["Pix Enviado", "Efetivado", "para ALINE PEREIRA", "-", "28/05/2026", "R$ 280,00"],
    ]
    p2 = [
        ["Pix Enviado", "Efetivado", "para BRUNO LIMA", "-", "17/05/2026", "R$ 468,68"],
        ["Pix Recebido", "Efetivado", "de CARLOS ALMEIDA", "-", "15/05/2026", "R$ 4.092,05"],
        ["Pix Enviado", "Efetivado", "para ITAU UNIBANCO", "-", "15/05/2026", "R$ 8.188,62"],
    ]
    escrever_pdf(caminho, [pagina(p1, True), pagina(p2, False)])


def main() -> None:
    base = os.path.dirname(os.path.abspath(__file__))
    os.makedirs(base, exist_ok=True)
    gerar_sem_bordas(os.path.join(base, "relatorio_tabela_sem_bordas.pdf"))
    gerar_html_multipagina(os.path.join(base, "tabela_html_multipagina.pdf"))
    gerar_sem_texto(os.path.join(base, "pdf_sem_texto.pdf"))
    print("fixtures gerados em", base)


if __name__ == "__main__":
    main()
