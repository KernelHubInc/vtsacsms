<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use Generator;
use RuntimeException;
use ZipArchive;

/** Minimal OOXML workbook; inline strings preserve identifiers and cannot become formulas. */
final class PortalExcelReport
{
    /** @param array{headers:list<string>,rows:Generator<int,list<scalar|null>>} $report
     * @return array{bytes:string,count:int}
     */
    public function render(array $report): array
    {
        $sheet = tmpfile();
        $path = tempnam(sys_get_temp_dir(), 'ps-report-');
        if ($sheet === false || $path === false) {
            throw new RuntimeException('Unable to create spreadsheet temporary files.');
        }
        $zip = new ZipArchive;
        try {
            fwrite($sheet, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><sheetData>');
            $this->row($sheet, $report['headers']);
            $count = 0;
            foreach ($report['rows'] as $row) {
                if (++$count > 1048575) {
                    throw new RuntimeException('Excel row limit exceeded. Narrow the report dates.');
                }
                $this->row($sheet, $row);
            }
            fwrite($sheet, '</sheetData></worksheet>');
            fflush($sheet);
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create spreadsheet archive.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $sheetPath = stream_get_meta_data($sheet)['uri'] ?? null;
            if (! is_string($sheetPath)) {
                throw new RuntimeException('Unable to locate spreadsheet temporary file.');
            }
            $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
            if (! $zip->close()) {
                throw new RuntimeException('Unable to finalize spreadsheet.');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Unable to read spreadsheet.');
            }

            return ['bytes' => $bytes, 'count' => $count];
        } finally {
            fclose($sheet);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  resource  $stream
     * @param  list<scalar|null>  $values
     */
    private function row($stream, array $values): void
    {
        fwrite($stream, '<row>');
        foreach ($values as $value) {
            if (is_int($value) || (is_float($value) && is_finite($value))) {
                fwrite($stream, '<c t="n"><v>'.$value.'</v></c>');

                continue;
            }
            $text = mb_substr((string) $value, 0, 32767);
            $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
            fwrite($stream, '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>');
        }
        fwrite($stream, '</row>');
    }
}
