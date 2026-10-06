<?php

namespace App\Application\Reports;

final class ReportPdf
{
    /**
     * @param  array{title: string, description: string, columns: array<int, string>, summary: array<string, string>, rows: array<int, array<int, string>>}  $report
     */
    public function render(array $report, string $from, string $to): string
    {
        $lines = [
            $report['title'],
            'Periodo: '.date('d/m/Y', strtotime($from)).' a '.date('d/m/Y', strtotime($to)),
            '',
        ];
        foreach ($report['summary'] as $label => $value) {
            $lines[] = $label.': '.$value;
        }
        $lines[] = '';
        $lines[] = implode(' | ', $report['columns']);
        $lines[] = str_repeat('-', 95);
        foreach ($report['rows'] as $row) {
            $lines[] = implode(' | ', $row);
        }
        if ($report['rows'] === []) {
            $lines[] = 'Sem registros para o periodo selecionado.';
        }

        $pages = array_chunk($lines, 48);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $pageReferences = [];

        foreach ($pages as $index => $pageLines) {
            $pageObject = 4 + ($index * 2);
            $streamObject = $pageObject + 1;
            $pageReferences[] = $pageObject.' 0 R';
            $content = "BT\n/F1 9 Tf\n45 790 Td\n";
            foreach ($pageLines as $line) {
                $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
                if ($encoded === false) {
                    throw new \RuntimeException('Unable to encode report text for PDF export.');
                }
                $wrappedLines = explode("\n", wordwrap($encoded, 105, "\n", true));
                foreach ($wrappedLines as $wrappedLine) {
                    $content .= '('.$this->escape($wrappedLine).") Tj\n0 -15 Td\n";
                }
            }
            $content .= 'ET';
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$streamObject.' 0 R >>';
            $objects[$streamObject] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageReferences).'] /Count '.count($pages).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $objectCount = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$objectCount}\n0000000000 65535 f \n";
        for ($number = 1; $number < $objectCount; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= "trailer\n<< /Size {$objectCount} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
