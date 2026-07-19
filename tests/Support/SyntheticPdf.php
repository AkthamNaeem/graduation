<?php

namespace Tests\Support;

class SyntheticPdf
{
    /** @param array<int, string> $lines */
    public static function make(array $lines, ?string $mailto = null): string
    {
        $commands = ['BT', '/F1 11 Tf', '50 750 Td', '14 TL'];
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $commands[] = 'T*';
            }
            $commands[] = '('.self::escape($line).') Tj';
        }
        $commands[] = 'ET';
        $stream = implode("\n", $commands);

        $annotation = $mailto === null ? '' : ' /Annots [6 0 R]';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R'.$annotation.' >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        if ($mailto !== null) {
            $objects[6] = '<< /Type /Annot /Subtype /Link /Rect [45 700 300 720] /A << /S /URI /URI ('.self::escape($mailto).') >> >>';
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; $number++) {
            $pdf .= isset($offsets[$number])
                ? sprintf("%010d 00000 n \n", $offsets[$number])
                : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
