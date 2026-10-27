"""Execução: python3 -m unittest discover -s tests/python -v"""

import importlib.util
import tempfile
import unittest
from pathlib import Path

MODULO = Path(__file__).resolve().parents[2] / 'scripts' / 'leitor_planilha.py'
spec = importlib.util.spec_from_file_location('leitor_planilha', MODULO)
leitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(leitor)

XML_TESTE = '''<?xml version="1.0"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
  <Worksheet ss:Name="Transações">
    <Table>
      <Row><Cell><Data ss:Type="String">Filtros</Data></Cell></Row>
      <Row ss:Index="3">
        <Cell><Data ss:Type="String">Cart&atilde;o</Data></Cell>
        <Cell ss:Index="3"><Data ss:Type="String">Valor &amp; Taxas</Data></Cell>
        <Cell><Data ss:Type="String">Data</Data></Cell>
      </Row>
      <Row>
        <Cell><Data ss:Type="String">0000123</Data></Cell>
        <Cell ss:Index="3"><Data ss:Type="Number">123.45</Data></Cell>
        <Cell><Data ss:Type="DateTime">2026-09-15T00:00:00.000</Data></Cell>
      </Row>
      <Row><Cell ss:MergeAcross="2"><Data ss:Type="String">Observa&ccedil;&atilde;o</Data></Cell></Row>
    </Table>
  </Worksheet>
  <Worksheet ss:Name="Outra"><Table><Row><Cell><Data ss:Type="String">OK</Data></Cell></Row></Table></Worksheet>
</Workbook>'''


class LeitorExcelXml2003Test(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name) / 'documento.xls'

    def tearDown(self):
        self.temp.cleanup()

    def test_detecta_e_le_planilha_com_entidades_indices_datas_e_mesclagem(self):
        self.path.write_bytes(XML_TESTE.encode())
        self.assertTrue(leitor.eh_excel_xml_2003(str(self.path)))
        abas = leitor.ler_excel_xml_2003(str(self.path))
        self.assertEqual(list(abas), ['Transações', 'Outra'])
        self.assertEqual(abas['Transações'][1], [])  # ss:Index na linha 3
        self.assertEqual(abas['Transações'][2], ['Cartão', '', 'Valor & Taxas', 'Data'])
        self.assertEqual(abas['Transações'][3], ['0000123', '', '123.45', '15/09/2026'])
        self.assertEqual(abas['Transações'][4], ['Observação', '', ''])

    def test_excel_xml_2003_em_utf16(self):
        self.path.write_bytes(XML_TESTE.encode('utf-16'))
        self.assertTrue(leitor.eh_excel_xml_2003(str(self.path)))
        abas = leitor.ler_excel_xml_2003(str(self.path))
        self.assertEqual(abas['Transações'][2][0], 'Cartão')

    def test_formato_nao_xml_2003_e_ignorado(self):
        self.path.write_bytes(b'PK\x03\x04')
        self.assertFalse(leitor.eh_excel_xml_2003(str(self.path)))
        self.path.write_bytes(bytes.fromhex('D0CF11E0A1B11AE1'))
        self.assertFalse(leitor.eh_excel_xml_2003(str(self.path)))

    def test_nao_aceita_declaracoes_xml_inseguras(self):
        self.path.write_text(XML_TESTE.replace('<Workbook', '<!DOCTYPE Workbook [<!ENTITY teste "x">]><Workbook', 1))
        with self.assertRaisesRegex(ValueError, 'não permitidas'):
            leitor.ler_excel_xml_2003(str(self.path))

    def test_xml_invalido_retorna_mensagem_controlada(self):
        self.path.write_text(XML_TESTE.replace('</Workbook>', ''))
        with self.assertRaisesRegex(ValueError, 'XML inválida'):
            leitor.ler_excel_xml_2003(str(self.path))


if __name__ == '__main__':
    unittest.main()
