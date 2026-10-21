<?php

namespace App\Services\Hrms;

class OfferLetterPdf
{
    public function render(string $body): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $commands = ["BT", "/F1 11 Tf", "48 800 Td", "16 TL"];

        foreach ($lines as $line) {
            $commands[] = '('.$this->escape($line).') Tj';
            $commands[] = 'T*';
        }

        $commands[] = 'ET';
        $stream = implode("\n", $commands);
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            "4 0 obj << /Length ".strlen($stream)." >> stream\n".$stream."\nendstream\nendobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer << /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function escape(string $value): string
    {
        $clean = str_replace('₹', 'INR ', $value);
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $clean) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }
}
