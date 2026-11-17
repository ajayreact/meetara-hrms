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

    /** Outer safe margin in millimetres. */
    public const MARGIN_MM = 2.4;

    private const NAVY = [15, 40, 80];

    private const NAVY_DEEP = [8, 28, 58];

    private const ORANGE = [234, 98, 22];

    private const ORANGE_SOFT = [251, 146, 60];

    private const WHITE = [255, 255, 255];

    private const SLATE = [51, 65, 85];

    private const MUTED = [100, 116, 139];

    private const PANEL = [248, 250, 252];

    /**
     * @param  array<string, mixed>  $card
     */
    public function render(array $card): string
    {
        $this->requireFields($card);

        $pdf = new \FPDF('P', 'mm', [self::WIDTH_MM, self::HEIGHT_MM]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

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
        $this->pageCanvas($pdf);

        $headerBottom = $this->headerBand($pdf, (string) ($card['companyDisplayName'] ?? ''), $this->absolutePath($card['logoPath'] ?? null), 'EMPLOYEE IDENTITY CARD');
        $this->accentBar($pdf, $headerBottom);

        $innerX = self::MARGIN_MM + 1.2;
        $innerW = self::WIDTH_MM - (($innerX) * 2);
        $footerTop = $this->footerTop();
        $y = $headerBottom + 2.6;

        $photoW = 21.0;
        $photoH = 26.0;
        $photoX = (self::WIDTH_MM - $photoW) / 2;
        $this->photoFrame($pdf, $photoX, $y, $photoW, $photoH, $this->absolutePath($card['photoPath'] ?? null));
        $y += $photoH + 2.4;

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetXY($innerX, $y);
        $pdf->MultiCell($innerW, 3.3, $this->text($card['name'] ?? ''), 0, 'C');
        $y = min($pdf->GetY() + 1.1, $footerTop - 22.0);

        $this->thinAccent($pdf, $y);
        $y += 2.0;

        $y = $this->metaRow($pdf, $y, $innerX, $innerW, 'EMPLOYEE ID', (string) ($card['employeeCode'] ?? ''));
        $y = $this->metaRow($pdf, $y, $innerX, $innerW, 'DESIGNATION', (string) ($card['designation'] ?? ''));
        $this->metaRow($pdf, $y, $innerX, $innerW, 'DEPARTMENT', (string) ($card['department'] ?? ''));

        $this->footerBand($pdf, 'VELTRIX DIGITAL SOLUTIONS');
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function back(\FPDF $pdf, array $card): void
    {
        $pdf->AddPage();
        $this->pageCanvas($pdf);

        $headerBottom = $this->headerBand($pdf, (string) ($card['companyDisplayName'] ?? ''), $this->absolutePath($card['logoPath'] ?? null), 'VERIFICATION DETAILS');
        $this->accentBar($pdf, $headerBottom);

        $innerX = self::MARGIN_MM + 1.2;
        $innerW = self::WIDTH_MM - (($innerX) * 2);
        $footerTop = $this->footerTop();
        $y = $headerBottom + 2.4;

        $y = $this->detailBlock($pdf, $y, $innerX, $innerW, 'EMAIL', (string) ($card['email'] ?? ''));
        $y = $this->detailBlock($pdf, $y, $innerX, $innerW, 'BLOOD GROUP', (string) ($card['bloodGroup'] ?? ''));
        $y = $this->detailBlock($pdf, $y, $innerX, $innerW, 'EMERGENCY CONTACT', (string) ($card['emergencyContactNumber'] ?? ''));
        $y += 1.0;

        $qrPath = $this->qrPng((string) ($card['verifyUrl'] ?? ''));
        $qrSize = 20.0;
        $qrX = (self::WIDTH_MM - $qrSize) / 2;
        $framePad = 1.0;
        $maxQrBottom = $footerTop - 14.5;
        if ($y + $qrSize + ($framePad * 2) > $maxQrBottom) {
            $qrSize = max(16.0, $maxQrBottom - $y - ($framePad * 2));
            $qrX = (self::WIDTH_MM - $qrSize) / 2;
        }

        try {
            $pdf->SetFillColor(...self::WHITE);
            $pdf->SetDrawColor(...self::ORANGE);
            $pdf->SetLineWidth(0.35);
            $pdf->Rect($qrX - $framePad, $y - $framePad, $qrSize + ($framePad * 2), $qrSize + ($framePad * 2), 'DF');
            $pdf->Image($qrPath, $qrX, $y, $qrSize, $qrSize);
            $y += $qrSize + $framePad + 1.2;
        } finally {
            if (is_file($qrPath)) {
                unlink($qrPath);
            }
        }

        $pdf->SetTextColor(...self::ORANGE);
        $pdf->SetFont('Helvetica', 'B', 5.5);
        $pdf->SetXY($innerX, $y);
        $pdf->Cell($innerW, 2.4, 'SCAN TO VERIFY EMPLOYEE', 0, 1, 'C');
        $y = $pdf->GetY() + 0.6;

        $addressBudget = $footerTop - $y - 0.8;
        if ($addressBudget >= 6.0) {
            $pdf->SetDrawColor(...self::ORANGE_SOFT);
            $pdf->SetLineWidth(0.2);
            $pdf->Line($innerX + 4, $y, self::WIDTH_MM - $innerX - 4, $y);
            $y += 1.0;

            $pdf->SetTextColor(...self::MUTED);
            $pdf->SetFont('Helvetica', 'B', 4.8);
            $pdf->SetXY($innerX, $y);
            $pdf->Cell($innerW, 2.0, 'OFFICE ADDRESS', 0, 1, 'C');

            $address = $this->text($card['footerAddress'] ?? '');
            $pdf->SetTextColor(...self::SLATE);
            $pdf->SetFont('Helvetica', '', 5.0);
            $pdf->SetXY($innerX, $pdf->GetY());
            $pdf->MultiCell($innerW, 2.2, $this->truncate($address, 90), 0, 'C');
        }

        $this->footerBand($pdf, 'AUTHORIZED CORPORATE ID');
    }

    private function pageCanvas(\FPDF $pdf): void
    {
        $pdf->SetFillColor(...self::WHITE);
        $pdf->Rect(0, 0, self::WIDTH_MM, self::HEIGHT_MM, 'F');

        $pdf->SetDrawColor(...self::NAVY);
        $pdf->SetLineWidth(0.55);
        $pdf->Rect(0.8, 0.8, self::WIDTH_MM - 1.6, self::HEIGHT_MM - 1.6);

        $pdf->SetDrawColor(...self::ORANGE);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect(1.35, 1.35, self::WIDTH_MM - 2.7, self::HEIGHT_MM - 2.7);
    }

    private function headerBand(\FPDF $pdf, string $companyName, ?string $logoPath, string $subtitle): float
    {
        $height = 16.8;
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect(1.35, 1.35, self::WIDTH_MM - 2.7, $height, 'F');

        $pdf->SetFillColor(...self::NAVY_DEEP);
        $pdf->Rect(1.35, 1.35 + $height - 1.6, self::WIDTH_MM - 2.7, 1.6, 'F');

        [$logoW, $logoH] = $this->logoBox($logoPath, 36.0, 7.2);
        $logoX = (self::WIDTH_MM - $logoW) / 2;
        $logoY = 2.0;

        $pdf->SetFillColor(...self::WHITE);
        $pdf->Rect($logoX - 0.5, $logoY - 0.35, $logoW + 1.0, $logoH + 0.7, 'F');

        if ($logoPath !== null) {
            // Width-only Image call preserves the source aspect ratio (no stretch).
            $pdf->Image($logoPath, $logoX, $logoY, $logoW);
        } else {
            $pdf->SetTextColor(...self::NAVY);
            $pdf->SetFont('Helvetica', 'B', 7);
            $pdf->SetXY($logoX, $logoY + max(0.8, ($logoH / 2) - 1.2));
            $pdf->Cell($logoW, 3, 'V', 0, 0, 'C');
        }

        $textY = $logoY + $logoH + 0.55;
        $pdf->SetTextColor(...self::WHITE);
        $pdf->SetFont('Helvetica', 'B', 6.2);
        $pdf->SetXY(3, $textY);
        $company = $this->text($companyName !== '' ? $companyName : 'Veltrix Digital Solutions');
        $pdf->Cell(self::WIDTH_MM - 6, 2.2, $this->truncate($company, 32), 0, 1, 'C');

        $pdf->SetTextColor(...self::ORANGE_SOFT);
        $pdf->SetFont('Helvetica', 'B', 4.4);
        $pdf->SetXY(3, $pdf->GetY());
        $pdf->Cell(self::WIDTH_MM - 6, 1.9, $subtitle, 0, 1, 'C');

        return 1.35 + $height;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function logoBox(?string $logoPath, float $maxW, float $maxH): array
    {
        if ($logoPath === null || ! is_file($logoPath)) {
            return [min(7.0, $maxW), min(7.0, $maxH)];
        }

        $info = @getimagesize($logoPath);
        $srcW = is_array($info) ? (float) ($info[0] ?? 0) : 0.0;
        $srcH = is_array($info) ? (float) ($info[1] ?? 0) : 0.0;

        if ($srcW <= 0 || $srcH <= 0) {
            return [$maxW, min($maxH, $maxW * 0.28)];
        }

        $scale = min($maxW / $srcW, $maxH / $srcH);

        return [$srcW * $scale, $srcH * $scale];
    }

    private function accentBar(\FPDF $pdf, float $y): void
    {
        $pdf->SetFillColor(...self::ORANGE);
        $pdf->Rect(1.35, $y, self::WIDTH_MM - 2.7, 1.15, 'F');
    }

    private function footerBand(\FPDF $pdf, string $label): void
    {
        $height = 5.6;
        $y = $this->footerTop();
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect(1.35, $y, self::WIDTH_MM - 2.7, $height, 'F');

        $pdf->SetFillColor(...self::ORANGE);
        $pdf->Rect(1.35, $y, self::WIDTH_MM - 2.7, 0.6, 'F');

        $pdf->SetTextColor(...self::WHITE);
        $pdf->SetFont('Helvetica', 'B', 4.8);
        $pdf->SetXY(3, $y + 1.8);
        $pdf->Cell(self::WIDTH_MM - 6, 2.2, $label, 0, 0, 'C');
    }

    private function footerTop(): float
    {
        return self::HEIGHT_MM - 1.35 - 5.6;
    }

    private function photoFrame(\FPDF $pdf, float $x, float $y, float $w, float $h, ?string $photoPath): void
    {
        $pdf->SetFillColor(...self::PANEL);
        $pdf->Rect($x - 1.1, $y - 1.1, $w + 2.2, $h + 2.2, 'F');

        $pdf->SetDrawColor(...self::NAVY);
        $pdf->SetLineWidth(0.45);
        $pdf->Rect($x - 1.1, $y - 1.1, $w + 2.2, $h + 2.2);

        $pdf->SetDrawColor(...self::ORANGE);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($x - 0.55, $y - 0.55, $w + 1.1, $h + 1.1);

        if ($photoPath !== null) {
            $pdf->Image($photoPath, $x, $y, $w, $h);
        } else {
            $pdf->SetFillColor(226, 232, 240);
            $pdf->Rect($x, $y, $w, $h, 'F');
            $pdf->SetTextColor(...self::MUTED);
            $pdf->SetFont('Helvetica', 'B', 6);
            $pdf->SetXY($x, $y + ($h / 2) - 1.5);
            $pdf->Cell($w, 3, 'PHOTO', 0, 0, 'C');
        }
    }

    private function thinAccent(\FPDF $pdf, float $y): void
    {
        $width = 18;
        $x = (self::WIDTH_MM - $width) / 2;
        $pdf->SetFillColor(...self::ORANGE);
        $pdf->Rect($x, $y, $width, 0.55, 'F');
    }

    private function metaRow(\FPDF $pdf, float $y, float $x, float $width, string $label, string $value): float
    {
        $pdf->SetXY($x, $y);
        $pdf->SetFont('Helvetica', 'B', 4.6);
        $pdf->SetTextColor(...self::ORANGE);
        $pdf->Cell($width, 2.0, $label, 0, 1, 'C');

        $pdf->SetX($x);
        $pdf->SetFont('Helvetica', 'B', 6.5);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->MultiCell($width, 2.7, $this->text($value !== '' ? $value : '—'), 0, 'C');

        return $pdf->GetY() + 0.7;
    }

    private function detailBlock(\FPDF $pdf, float $y, float $x, float $width, string $label, string $value): float
    {
        $pdf->SetFillColor(...self::PANEL);
        $blockH = 6.4;
        $pdf->Rect($x, $y, $width, $blockH, 'F');

        $pdf->SetFillColor(...self::ORANGE);
        $pdf->Rect($x, $y, 0.7, $blockH, 'F');

        $pdf->SetXY($x + 1.4, $y + 0.55);
        $pdf->SetFont('Helvetica', 'B', 4.5);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Cell($width - 2.2, 1.9, $label, 0, 1, 'L');

        $pdf->SetXY($x + 1.4, $y + 2.7);
        $pdf->SetFont('Helvetica', 'B', 6.1);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->MultiCell($width - 2.2, 2.3, $this->text($value !== '' ? $value : '—'), 0, 'L');

        return $y + $blockH + 0.9;
    }

    private function qrPng(string $url): string
    {
        if ($url === '') {
            throw ValidationException::withMessages(['card' => 'A verification URL is required for the ID card QR code.']);
        }

        $qrCode = QrCode::create($url)->setSize(360)->setMargin(6);
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

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return rtrim(substr($value, 0, $max - 1)).'.';
    }
}
