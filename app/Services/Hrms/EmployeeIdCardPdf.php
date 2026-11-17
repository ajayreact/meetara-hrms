<?php

namespace App\Services\Hrms;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Validation\ValidationException;

class EmployeeIdCardPdf
{
    /** CR80 portrait width in millimetres. */
    public const WIDTH_MM = 53.98;

    /** CR80 portrait height in millimetres. */
    public const HEIGHT_MM = 85.60;

    /** Safe content margin in millimetres. */
    public const MARGIN_MM = 3.0;

    /**
     * @param  array<string, mixed>  $card
     */
    public function render(array $card): string
    {
        $this->requireFields($card);

        $pdf = new \FPDF('P', 'mm', [self::WIDTH_MM, self::HEIGHT_MM]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(self::MARGIN_MM, self::MARGIN_MM, self::MARGIN_MM);

        $this->front($pdf, $card);
        $this->back($pdf, $card);

        return $pdf->Output('S');
    }

    public function pages(string $pdf): int
    {
        preg_match_all('/\/Type \/Page(?!s)/', $pdf, $matches);

        return count($matches[0]);
    }

    /**
     * Merge individual CR80 card PDFs into one print-ready document.
     *
     * @param  list<string>  $pdfBinaries
     */
    public function merge(array $pdfBinaries): string
    {
        if ($pdfBinaries === []) {
            throw ValidationException::withMessages(['bulk' => 'No ID card PDFs were available to combine.']);
        }

        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm');
        $temps = [];

        try {
            foreach ($pdfBinaries as $bytes) {
                if (! is_string($bytes) || $bytes === '' || ! str_starts_with($bytes, '%PDF')) {
                    continue;
                }

                $temp = tempnam(sys_get_temp_dir(), 'idcard-merge-');
                if ($temp === false) {
                    throw ValidationException::withMessages(['bulk' => 'Could not prepare the combined ID card PDF.']);
                }

                file_put_contents($temp, $bytes);
                $temps[] = $temp;
                $count = $pdf->setSourceFile($temp);

                for ($page = 1; $page <= $count; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }

            if ($pdf->PageNo() < 1) {
                throw ValidationException::withMessages(['bulk' => 'No ID card PDFs were available to combine.']);
            }

            return $pdf->Output('S');
        } finally {
            foreach ($temps as $temp) {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function front(\FPDF $pdf, array $card): void
    {
        $pdf->AddPage();
        $contentWidth = self::WIDTH_MM - (self::MARGIN_MM * 2);
        $y = self::MARGIN_MM;

        $logo = $this->absolutePath($card['logoPath'] ?? null);
        if ($logo !== null) {
            $pdf->Image($logo, self::MARGIN_MM + (($contentWidth - 14) / 2), $y, 14, 14);
            $y += 16;
        } else {
            $pdf->SetDrawColor(180, 180, 180);
            $pdf->Rect(self::MARGIN_MM + (($contentWidth - 14) / 2), $y, 14, 14);
            $y += 16;
        }

        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->MultiCell($contentWidth, 3.5, $this->text($card['companyDisplayName'] ?? ''), 0, 'C');
        $y = $pdf->GetY() + 2;

        $photo = $this->absolutePath($card['photoPath'] ?? null);
        $photoW = 22;
        $photoH = 28;
        $photoX = self::MARGIN_MM + (($contentWidth - $photoW) / 2);
        if ($photo !== null) {
            $pdf->Image($photo, $photoX, $y, $photoW, $photoH);
        } else {
            $pdf->SetDrawColor(180, 180, 180);
            $pdf->Rect($photoX, $y, $photoW, $photoH);
        }
        $y += $photoH + 3;

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->MultiCell($contentWidth, 4, $this->text($card['name'] ?? ''), 0, 'C');
        $y = $pdf->GetY() + 1;

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->MultiCell($contentWidth, 3.2, $this->text($card['employeeCode'] ?? ''), 0, 'C');
        $y = $pdf->GetY() + 0.8;

        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->MultiCell($contentWidth, 3.2, $this->text($card['designation'] ?? ''), 0, 'C');
        $y = $pdf->GetY() + 0.5;

        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->MultiCell($contentWidth, 3.2, $this->text($card['department'] ?? ''), 0, 'C');
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function back(\FPDF $pdf, array $card): void
    {
        $pdf->AddPage();
        $contentWidth = self::WIDTH_MM - (self::MARGIN_MM * 2);
        $y = self::MARGIN_MM + 1;

        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->Cell($contentWidth, 4, 'Employee details', 0, 1, 'C');
        $y = $pdf->GetY() + 2;

        $pdf->SetFont('Helvetica', '', 6.5);
        $y = $this->detail($pdf, $y, $contentWidth, 'Email', (string) ($card['email'] ?? ''));
        $y = $this->detail($pdf, $y, $contentWidth, 'Blood group', (string) ($card['bloodGroup'] ?? ''));
        $y = $this->detail($pdf, $y, $contentWidth, 'Emergency', (string) ($card['emergencyContactNumber'] ?? ''));
        $y += 2;

        $qrPath = $this->qrPng((string) ($card['verifyUrl'] ?? ''));
        $qrSize = 22;
        $qrX = self::MARGIN_MM + (($contentWidth - $qrSize) / 2);

        try {
            $pdf->Image($qrPath, $qrX, $y, $qrSize, $qrSize);
            $y += $qrSize + 2;
        } finally {
            if (is_file($qrPath)) {
                unlink($qrPath);
            }
        }

        $pdf->SetFont('Helvetica', '', 5.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->MultiCell($contentWidth, 2.6, 'Scan to verify', 0, 'C');
        $y = $pdf->GetY() + 2;

        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Line(self::MARGIN_MM, $y, self::WIDTH_MM - self::MARGIN_MM, $y);
        $y += 2;

        $pdf->SetFont('Helvetica', '', 5.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->MultiCell($contentWidth, 2.6, $this->text($card['footerAddress'] ?? ''), 0, 'C');
    }

    private function detail(\FPDF $pdf, float $y, float $width, string $label, string $value): float
    {
        $pdf->SetXY(self::MARGIN_MM, $y);
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($width, 3, $this->text($label), 0, 1, 'L');
        $pdf->SetX(self::MARGIN_MM);
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->MultiCell($width, 3, $this->text($value !== '' ? $value : '—'), 0, 'L');

        return $pdf->GetY() + 1.2;
    }

    private function qrPng(string $url): string
    {
        if ($url === '') {
            throw ValidationException::withMessages(['card' => 'A verification URL is required for the ID card QR code.']);
        }

        $qrCode = QrCode::create($url)->setSize(280)->setMargin(4);
        $png = (new PngWriter)->write($qrCode)->getString();
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'id-card-qr-'.bin2hex(random_bytes(8)).'.png';
        file_put_contents($path, $png);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function requireFields(array $card): void
    {
        foreach (['name', 'employeeCode', 'verifyUrl'] as $field) {
            if (trim((string) ($card[$field] ?? '')) === '') {
                throw ValidationException::withMessages(['card' => 'ID card PDF is missing '.$field.'.']);
            }
        }
    }

    private function absolutePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        return null;
    }

    private function text(mixed $value): string
    {
        $text = trim((string) $value);
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);

        return is_string($converted) && $converted !== '' ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }
}
