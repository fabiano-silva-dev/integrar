<?php

namespace Tests\Unit;

use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use Tests\TestCase;

class EmpresaPastaDriveLinkTest extends TestCase
{
    public function test_usa_link_salvo_da_pasta(): void
    {
        $pasta = new EmpresaPastaDrive([
            'google_folder_id' => 'abc123',
            'google_web_link' => 'https://drive.google.com/drive/folders/abc123?usp=drive_link',
        ]);

        $this->assertSame(
            'https://drive.google.com/drive/folders/abc123?usp=drive_link',
            $pasta->urlDrive()
        );
    }

    public function test_monta_link_da_pasta_pelo_id(): void
    {
        $pasta = new EmpresaPastaDrive([
            'google_folder_id' => 'abc123',
        ]);

        $this->assertSame('https://drive.google.com/drive/folders/abc123', $pasta->urlDrive());
    }

    public function test_arquivo_usa_link_salvo_ou_id(): void
    {
        $comLink = new DocumentoRecebido([
            'drive_file_id' => 'file1',
            'drive_web_link' => 'https://drive.google.com/file/d/file1/view?usp=drive_link',
        ]);
        $soId = new DocumentoRecebido([
            'drive_file_id' => 'file2',
        ]);

        $this->assertSame('https://drive.google.com/file/d/file1/view?usp=drive_link', $comLink->urlDrive());
        $this->assertSame('https://drive.google.com/file/d/file2/view', $soId->urlDrive());
    }

    public function test_drive_file_id_da_copia_da_empresa(): void
    {
        $documento = new DocumentoRecebido([
            'empresa_id' => 10,
            'drive_file_id' => 'original',
            'metadados' => [
                'copias_drive' => [
                    ['empresa_id' => 10, 'drive_file_id' => 'copia-a'],
                    ['empresa_id' => 20, 'drive_file_id' => 'copia-b'],
                ],
            ],
        ]);

        $this->assertSame('copia-a', $documento->driveFileIdParaEmpresa(10));
        $this->assertSame('copia-b', $documento->driveFileIdParaEmpresa(20));
        $this->assertSame('original', $documento->driveFileIdParaEmpresa(99));
    }
}
