<?php

namespace App\Services\Hrms;

class PayslipPdf
{
    /**
     * @param  array<string, mixed>  $slip
     */
    public function render(array $slip): string
    {
        $currency = $slip['currency'] ? $slip['currency'].' ' : '';
        $lines = [
            (string) $slip['company'],
            'Payslip '.$slip['reference'],
            'Employee: '.$slip['employee'].' ('.$slip['code'].')',
            'Role: '.($slip['title'] ?: 'Not recorded').($slip['department'] ? ' · '.$slip['department'] : ''),
            'Date of joining: '.($slip['joined'] ?? 'Not recorded'),
            'Period: '.$slip['period'],
            'Payment status: '.($slip['paymentStatus'] ?? 'Pending'),
            'Payment date: '.($slip['paidOn'] ?: 'Not recorded'),
            'Payment method: '.($slip['paymentMethod'] ?: 'Not recorded'),
            'A recorded payment is not a bank transfer.',
            'Currency: '.($slip['currency'] ?: 'Not recorded'),
            '',
            'Earnings',
            'Basic '.$currency.$slip['basic'],
            'HRA '.$currency.$slip['hra'],
            'Special allowance '.$currency.$slip['special'],
            'Gross '.$currency.$slip['gross'],
            '',
            'Deductions',
            (string) ($slip['deductionNotice'] ?? 'PF, ESI, TDS, and unpaid leave are not configured'),
            'Recorded deduction total '.$currency.$slip['deductions'],
            '',
            'Net payable '.$currency.$slip['net'],
            'Generated '.$slip['generated'],
        ];
        $commands = ["BT", "/F1 12 Tf", "50 800 Td", "16 TL"];

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
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }
}
