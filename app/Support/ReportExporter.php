<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Turns a report (columns and rows) into a file: CSV that Excel opens, or a real .xlsx workbook.
 * Written without a spreadsheet library, which keeps the install small; the workbook is plain (one sheet, bold header).
 */
class ReportExporter
{
    /**
     * @param  array{title:string,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,mixed>>}  $report
     */
    public static function csv(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 names correctly
            fputcsv($out, array_map(fn ($c) => self::safe($c['label']), $report['columns']));
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => self::safe($row[$c['key']] ?? ''), $report['columns']));
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array{title:string,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,mixed>>}  $report
     */
    public static function xlsx(array $report, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'rep');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the workbook.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');

        $rows = '';
        $line = 1;
        $rows .= self::row($line++, array_map(fn ($c) => $c['label'], $report['columns']), true);
        foreach ($report['rows'] as $row) {
            $rows .= self::row($line++, array_map(fn ($c) => $row[$c['key']] ?? '', $report['columns']), false);
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->close();

        return response()->download($path, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    /** @param list<mixed> $cells */
    private static function row(int $number, array $cells, bool $bold): string
    {
        $xml = '';
        foreach ($cells as $i => $value) {
            $ref = self::column($i).$number;
            $style = $bold ? ' s="1"' : '';
            $xml .= is_int($value) || is_float($value)
                ? "<c r=\"{$ref}\"{$style}><v>{$value}</v></c>"
                : "<c r=\"{$ref}\"{$style} t=\"inlineStr\"><is><t xml:space=\"preserve\">".htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
        }

        return "<row r=\"{$number}\">{$xml}</row>";
    }

    /** 0 -> A, 25 -> Z, 26 -> AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** A cell that starts with = + - @ would run as a formula when opened; make it text. */
    private static function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
