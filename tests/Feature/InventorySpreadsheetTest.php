<?php

namespace Tests\Feature;

use App\Services\Networks\ReadInventorySpreadsheetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class InventorySpreadsheetTest extends TestCase
{
    public function test_csv_preserves_leading_zeroes_password_spaces_and_mixed_card_formats(): void
    {
        $file = UploadedFile::fake()->createWithContent('cards.csv', "\xEF\xBB\xBFusername,password\n001234,000007\nalpha,\" leading and trailing \"\n00111,\n");
        $rows = app(ReadInventorySpreadsheetService::class)->read($file);
        $this->assertSame([['username' => '001234', 'password' => '000007'], ['username' => 'alpha', 'password' => ' leading and trailing '], ['username' => '00111', 'password' => '']], $rows);
    }

    public function test_xlsx_reads_shared_inline_and_zero_padded_numeric_cells_without_evaluating_formulas(): void
    {
        $sheet = '<row r="1"><c r="A1" t="inlineStr"><is><t>username</t></is></c><c r="B1" t="inlineStr"><is><t>password</t></is></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2" s="1"><v>7</v></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>alpha</t></is></c><c r="B3" t="inlineStr"><is><t xml:space="preserve"> secret </t></is></c></row>';
        $rows = app(ReadInventorySpreadsheetService::class)->read($this->xlsx($sheet));
        $this->assertSame([['username' => '001234', 'password' => '000007'], ['username' => 'alpha', 'password' => ' secret ']], $rows);
    }

    #[DataProvider('invalidCells')]
    public function test_unsafe_or_ambiguous_xlsx_cells_are_rejected(string $cell): void
    {
        $sheet = '<row r="1"><c r="A1" t="inlineStr"><is><t>username</t></is></c></row><row r="2">'.$cell.'</row>';
        $this->expectException(ValidationException::class);
        app(ReadInventorySpreadsheetService::class)->read($this->xlsx($sheet));
    }

    public static function invalidCells(): array
    {
        return [['<c r="A2"><f>123+456</f><v>579</v></c>'], ['<c r="A2"><v>1234567890123456</v></c>'], ['<c r="A2"><v>1.234E+10</v></c>'], ['<c r="C2" t="inlineStr"><is><t>secret</t></is></c>'], ['<c r="A2" t="s"><v>99</v></c>']];
    }

    public function test_xml_entities_and_unknown_headers_are_rejected(): void
    {
        try {
            app(ReadInventorySpreadsheetService::class)->read(UploadedFile::fake()->createWithContent('cards.csv', "username,price\ncard,200\n"));
            $this->fail('Unknown header was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->expectException(ValidationException::class);
        app(ReadInventorySpreadsheetService::class)->read($this->xlsx('<row r="1"/>', true));
    }

    private function xlsx(string $rows, bool $entity = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qanetwork-xlsx-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Cards" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>001234</t></si></sst>');
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts><numFmt numFmtId="164" formatCode="000000"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', ($entity ? '<!DOCTYPE worksheet [<!ENTITY card "secret">]>' : '').'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('cards.xlsx', $contents);
    }
}
