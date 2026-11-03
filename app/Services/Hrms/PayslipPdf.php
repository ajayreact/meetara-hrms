<?php

namespace App\Services\Hrms;

use App\Support\IndianAmount;

class PayslipPdf
{
    /**
     * @param  array<string, mixed>  $slip
     */
    public function render(array $slip): string
    {
        if (! empty($slip['imported'])) {
            return $this->document($this->importedLines($slip));
        }

        $currency = $slip['currency'] ? $slip['currency'].' ' : '';
        $lines = [
            (string) ($slip['legalName'] ?? $slip['company']),
        ];

        if (! empty($slip['legalAddress'])) {
            $lines[] = (string) $slip['legalAddress'];
        }

        $lines = [
            ...$lines,
            'Payslip '.$slip['reference'],
            'Employee: '.$slip['employee'].' ('.$slip['code'].')',
            'Designation: '.($slip['title'] ?: 'Not recorded'),
            'Department: '.($slip['department'] ?: 'Not recorded'),
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
        ];

        if (($slip['currency'] ?? null) === 'INR') {
            $lines[] = 'Net payable in words '.IndianAmount::words((string) $slip['net']);
        }

        $lines[] = 'Generated '.($slip['generatedAt'] ?? $slip['generated']);

        return $this->document($lines);
    }

    /**
     * @param  array<string, mixed>  $slip
     * @return list<string>
     */
    private function importedLines(array $slip): array
    {
        $currency = $slip['currency'] ? $slip['currency'].' ' : '';
        $supplied = is_array($slip['supplied'] ?? null) ? $slip['supplied'] : [];
        $lines = [(string) ($slip['legalName'] ?? $slip['company'])];

        if (! empty($slip['legalAddress'])) {
            $lines[] = (string) $slip['legalAddress'];
        }

        $lines = [
            ...$lines,
            'Payslip '.$slip['reference'],
            'Employee: '.$slip['employee'].' ('.$slip['code'].')',
            'Designation: '.($slip['title'] ?: 'Not recorded'),
            'Department: '.($slip['department'] ?: 'Not recorded'),
            'Date of joining: '.($slip['joined'] ?? 'Not recorded'),
            'Period: '.$slip['period'],
            '',
            'Earnings',
        ];

        foreach (['basic' => 'Basic salary', 'hra' => 'HRA', 'special' => 'Special allowance', 'other_earnings' => 'Other earnings', 'gross' => 'Gross salary'] as $key => $label) {
            if (isset($supplied[$key])) {
                $lines[] = $label.' '.$currency.$supplied[$key];
            }
        }

        $lines[] = '';
        $lines[] = 'Deductions';
        $deductions = ['pf' => 'PF', 'esi' => 'ESI', 'professional_tax' => 'Professional tax', 'tds' => 'TDS', 'other_deductions' => 'Other deductions', 'deductions' => 'Total deductions'];
        $anyDeduction = false;

        foreach ($deductions as $key => $label) {
            if (isset($supplied[$key])) {
                $lines[] = $label.' '.$currency.$supplied[$key];
                $anyDeduction = true;
            }
        }

        if (! $anyDeduction) {
            $lines[] = 'No deductions were included in the imported payroll file.';
        }

        $lines[] = '';
        $lines[] = 'Net payable '.$currency.($supplied['net'] ?? $slip['net']);

        if (($slip['currency'] ?? null) === 'INR' && isset($supplied['net'])) {
            $lines[] = 'Net payable in words '.IndianAmount::words((string) $supplied['net']);
        }

        foreach (['paid_days' => 'Paid days', 'working_days' => 'Working days', 'lwp' => 'Leave without pay'] as $key => $label) {
            if (isset($supplied[$key])) {
                $lines[] = $label.' '.$supplied[$key];
            }
        }

        $lines[] = 'Generated '.($slip['generatedAt'] ?? $slip['generated']);

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function document(array $lines): string
    {
        $commands = ['BT', '/F1 11 Tf', '40 800 Td', '14 TL'];

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
            '4 0 obj << /Length '.strlen($stream)." >> stream\n".$stream."\nendstream\nendobj\n",
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

        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function escape(string $value): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }
}
