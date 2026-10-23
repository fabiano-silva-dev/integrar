"""Regressões do C6: a data contábil determina o DTPOSTED do OFX."""

import json
import re
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPTS_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = SCRIPTS_DIR.parent
sys.path.insert(0, str(SCRIPTS_DIR))

from conversor_extrato_c6_pdf_ofx import converter_pdf_c6_para_ofx  # noqa: E402


class ConversorExtratoC6Test(unittest.TestCase):
    def test_fixture_pdf_usa_data_contabil_e_preserva_movimentos(self):
        pdf = REPO_DIR / 'docs/extratos/c6/extrato-anonimizado.pdf'
        with tempfile.TemporaryDirectory() as pasta:
            ofx = Path(pasta) / 'c6.ofx'
            preview = Path(pasta) / 'c6.json'
            resultado = converter_pdf_c6_para_ofx(str(pdf), str(ofx), str(preview))
            lancamentos = json.loads(preview.read_text(encoding='utf-8'))
            conteudo = ofx.read_text(encoding='latin-1')

        self.assertEqual(89, resultado['total_lancamentos'])
        self.assertEqual(89, len(lancamentos))
        self.assertEqual(17, sum(item['data'] == '08/09/2026' for item in lancamentos))
        self.assertEqual(1, sum(item['data'] == '17/09/2026' for item in lancamentos))
        self.assertEqual('31/08/2026', resultado['data_inicial'])
        self.assertEqual('30/09/2026', resultado['data_final'])
        self.assertAlmostEqual(7898.25, resultado['saldo_final'], places=2)
        self.assertIn('<BANKID>336</BANKID>', conteudo)
        self.assertIn('<ACCTID>000000001</ACCTID>', conteudo)
        self.assertIn('<DTPOSTED>20260917000000.000[-3:-03]</DTPOSTED>', conteudo)
        self.assertIn('<DTPOSTED>20260908000000.000[-3:-03]</DTPOSTED>', conteudo)
        self.assertEqual(89, len(re.findall(r'<STMTTRN>', conteudo)))
        self.assertEqual(89, len(set(re.findall(r'<FITID>([^<]+)', conteudo))))
        self.assertNotIn('<MEMO>Saldo do dia', conteudo)


if __name__ == '__main__':
    unittest.main()
