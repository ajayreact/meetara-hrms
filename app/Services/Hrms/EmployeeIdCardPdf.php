<?php

namespace App\Services\Hrms;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;

class EmployeeIdCardPdf
{
    /** CR80 portrait width in millimetres. */
    public const WIDTH_MM = 53.98;

    /** CR80 portrait height in millimetres. */
    public const HEIGHT_MM = 85.60;

    /** Outer safe margin in millimetres. */
    public const MARGIN_MM = 2.4;

    /** Front navy header height (ratio of card height). */
    private const FRONT_NAVY_TOP_RATIO = 0.44;

    /** Back-side navy footer starts below verification content (ratio of card height). */
    private const BACK_WAVE_NAVY_TOP_RATIO = 0.83;

    private const NAVY = [10, 32, 84];

    private const NAVY_DEEP = [6, 22, 62];

    private const RED = [214, 62, 48];

    private const ORANGE = [241, 90, 36];

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

        $pdf = new IdCardCanvas('P', 'mm', [self::WIDTH_MM, self::HEIGHT_MM]);
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

        $pdf = new Fpdi('P', 'mm');
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
    private function front(IdCardCanvas $pdf, array $card): void
    {
        $pdf->AddPage();
        $this->baseCanvas($pdf);
        $pdf->clipRoundRect(0, 0, self::WIDTH_MM, self::HEIGHT_MM, 2.8);
        $this->paintFrontVectors($pdf);

        $photoW = 20.6;
        $photoH = 24.4;
        $photoX = (self::WIDTH_MM - $photoW) / 2;
        $photoY = 18.0;
        $this->placePortrait($pdf, $photoX, $photoY, $photoW, $photoH, $this->absolutePath($card['photoPath'] ?? null));

        $logoPath = $this->absolutePath($card['logoPath'] ?? null);
        $logoOnNavy = $this->logoOnNavy($logoPath);
        [$logoW, $logoH] = $this->logoBox($logoOnNavy ?? $logoPath, 28.6, 7.7);
        $logoX = (self::WIDTH_MM - $logoW) / 2;
        $logoY = 4.5;
        try {
            if ($logoOnNavy !== null || $logoPath !== null) {
                $pdf->Image($logoOnNavy ?? $logoPath, $logoX, $logoY, $logoW);
            }
        } finally {
            if ($logoOnNavy !== null && is_file($logoOnNavy)) {
                unlink($logoOnNavy);
            }
        }

        $this->styledName($pdf, (string) ($card['name'] ?? ''), 44.8);

        $designation = trim($this->text($card['designation'] ?? ''));
        if ($designation !== '') {
            $this->centerTracked($pdf, $this->truncate($designation, 34), 49.8, 6.2, 0.14, self::SLATE, 2.4);
        }

        $y = 55.0;
        $dob = $this->formatDate($card['dateOfBirth'] ?? null);
        $y = $this->iconRow($pdf, $y, 'id', 'EMP ID', (string) ($card['employeeCode'] ?? ''));
        $y = $this->iconRow($pdf, $y, 'mail', 'EMAIL', (string) ($card['email'] ?? ''));
        $y = $this->iconRow($pdf, $y, 'drop', 'BLOOD GROUP', (string) ($card['bloodGroup'] ?? ''));
        $this->iconRow($pdf, $y, 'calendar', 'DOB', $dob);

        $this->frontAddressFooter($pdf, $card);
        $pdf->endClip();
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function back(IdCardCanvas $pdf, array $card): void
    {
        $pdf->AddPage();
        $this->baseCanvas($pdf);
        $pdf->clipRoundRect(0, 0, self::WIDTH_MM, self::HEIGHT_MM, 2.8);
        $this->paintBackVectors($pdf);

        $qrSize = 18.8;
        $qrX = (self::WIDTH_MM - $qrSize) / 2;
        $qrY = 20.7;
        $frame = 1.75;
        $pdf->fillRoundRect($qrX - $frame, $qrY - $frame + 0.28, $qrSize + ($frame * 2), $qrSize + ($frame * 2), 2.15, [232, 214, 204]);
        $pdf->fillRoundRect($qrX - $frame, $qrY - $frame, $qrSize + ($frame * 2), $qrSize + ($frame * 2), 2.15, self::ORANGE);
        $pdf->fillRoundRect($qrX - $frame + 0.55, $qrY - $frame + 0.55, $qrSize + ($frame * 2) - 1.1, $qrSize + ($frame * 2) - 1.1, 1.7, self::WHITE);

        $logoPath = $this->trimLogo($this->absolutePath($card['logoPath'] ?? null));
        [$logoW, $logoH] = $this->logoBox($logoPath, 27.6, 7.4);
        $logoX = (self::WIDTH_MM - $logoW) / 2;
        try {
            if ($logoPath !== null) {
                $pdf->Image($logoPath, $logoX, 6.5, $logoW);
            }
        } finally {
            if ($logoPath !== null && is_file($logoPath) && str_contains($logoPath, 'id-card-logo-')) {
                unlink($logoPath);
            }
        }

        $innerX = 4.2;
        $innerW = self::WIDTH_MM - ($innerX * 2);
        $y = $qrY;

        $qrPath = $this->qrPng((string) ($card['verifyUrl'] ?? ''));
        try {
            $pdf->Image($qrPath, $qrX, $y, $qrSize, $qrSize);
            $y += $qrSize + 2.8;
        } finally {
            if (is_file($qrPath)) {
                unlink($qrPath);
            }
        }

        $this->scanPill($pdf);

        $issued = $this->formatDate($card['issuedAt'] ?? null);
        $cardNumber = trim((string) ($card['cardNumber'] ?? ''));
        $mid = self::WIDTH_MM / 2;
        $metaY = 50.0;
        $this->metaPair($pdf, $metaY, 4.6, $mid - 1.5, 'calendar', 'ISSUED DATE', $issued !== '' ? $issued : '-');
        $pdf->SetDrawColor(214, 219, 228);
        $pdf->SetLineWidth(0.18);
        $pdf->Line($mid, $metaY + 0.7, $mid, $metaY + 5.4);
        $this->metaPair($pdf, $metaY, $mid + 1.5, self::WIDTH_MM - 4.6, 'card', 'CARD NO', $cardNumber !== '' ? $cardNumber : '-');

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('Helvetica', 'B', 8.0);
        $pdf->SetXY($innerX, 57.2);
        $company = $this->text(($card['companyDisplayName'] ?? '') !== '' ? $card['companyDisplayName'] : 'Veltrix Digital Solutions');
        $pdf->Cell($innerW, 3.4, $this->truncate($company, 32), 0, 1, 'C');
        $ruleW = 14.0;
        $ruleY = $pdf->GetY() + 0.7;
        $pdf->SetDrawColor(...self::ORANGE);
        $pdf->SetLineWidth(0.4);
        $pdf->Line((self::WIDTH_MM - $ruleW) / 2, $ruleY, (self::WIDTH_MM + $ruleW) / 2, $ruleY);

        $this->placeHrSignature($pdf, $card);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->SetLineWidth(0.16);
        $pdf->Line(($mid - 11), 67.6, ($mid + 11), 67.6);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->SetFont('Helvetica', '', 4.7);
        $pdf->SetXY($innerX, 68.6);
        $pdf->Cell($innerW, 2.0, 'Authorized Signatory (HR)', 0, 1, 'C');
        $pdf->endClip();
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function placeHrSignature(IdCardCanvas $pdf, array $card): void
    {
        $lineY = 67.6;
        $maxW = 24.0;
        $maxH = 5.2;
        $path = $card['signaturePath'] ?? null;

        if (is_string($path) && is_file($path)) {
            $size = @getimagesize($path);
            $width = $maxW;
            $height = $maxH;

            if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
                $aspect = $size[0] / $size[1];
                $height = $width / $aspect;

                if ($height > $maxH) {
                    $height = $maxH;
                    $width = $height * $aspect;
                }
            }

            $x = (self::WIDTH_MM - $width) / 2;
            $y = $lineY - $height - 0.35;

            try {
                $pdf->Image($path, $x, $y, $width, $height);

                return;
            } catch (\Throwable) {
                // Fall through to the empty signature placeholder.
            }
        }

        $width = 22.0;
        $height = 4.8;
        $x = (self::WIDTH_MM - $width) / 2;
        $y = $lineY - $height - 0.35;
        $pdf->SetDrawColor(148, 163, 184);
        $pdf->SetLineWidth(0.18);
        $pdf->Rect($x, $y, $width, $height);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->SetFont('Helvetica', '', 4.2);
        $pdf->SetXY($x, $y + (($height - 2.0) / 2));
        $pdf->Cell($width, 2.0, 'HR signature', 0, 0, 'C');
    }

    private function baseCanvas(IdCardCanvas $pdf): void
    {
        $pdf->SetFillColor(...self::WHITE);
        $pdf->Rect(0, 0, self::WIDTH_MM, self::HEIGHT_MM, 'F');
    }

    private function paintFrontVectors(IdCardCanvas $pdf): void
    {
        $w = self::WIDTH_MM;
        $navy = [
            [0.0, 20.7, 3.0, 21.0, 8.0, 23.2, 12.0, 24.2],
            [12.0, 24.2, 22.0, 24.8, 34.0, 25.6, 43.0, 25.9],
            [43.0, 25.9, 47.0, 24.6, 50.0, 21.2, $w, 18.5],
        ];
        $orange = [
            [0.0, 30.6, 4.0, 29.6, 8.0, 28.6, 14.0, 28.0],
            [14.0, 28.0, 26.0, 27.6, 36.0, 28.2, 44.0, 27.6],
            [44.0, 27.6, 48.0, 25.4, 51.0, 22.0, $w, 19.5],
        ];
        $red = [
            [0.0, 40.2, 4.0, 37.8, 8.0, 35.2, 14.0, 33.0],
            [14.0, 33.0, 24.0, 31.4, 36.0, 30.8, 46.0, 29.4],
            [46.0, 29.4, 50.0, 28.0, 52.5, 26.8, $w, 26.2],
        ];

        $pdf->fillAboveSegments($navy, self::NAVY);
        $pdf->fillRibbonSegments($navy, $orange, self::ORANGE);
        $pdf->fillRibbonSegments($orange, $red, self::RED);

        $footerOrangeTop = [
            [0.0, 74.1, 14.0, 73.6, 30.0, 72.8, 43.0, 71.5],
            [43.0, 71.5, 48.0, 70.2, 51.5, 69.0, $w, 68.2],
        ];
        $footerOrangeBottom = [
            [0.0, 75.0, 14.0, 74.6, 30.0, 73.8, 43.0, 72.4],
            [43.0, 72.4, 48.0, 71.2, 51.5, 70.0, $w, 69.4],
        ];
        $footerNavy = [
            [0.0, 75.8, 14.0, 75.4, 30.0, 74.6, 43.0, 73.2],
            [43.0, 73.2, 48.0, 72.0, 51.5, 70.8, $w, 70.6],
        ];
        $pdf->fillRibbonSegments($footerOrangeTop, $footerOrangeBottom, self::ORANGE);
        $pdf->fillRibbonSegments($footerOrangeBottom, $footerNavy, self::RED);
        $pdf->fillBelowSegments($footerNavy, self::NAVY);
    }

    private function paintBackVectors(IdCardCanvas $pdf): void
    {
        $w = self::WIDTH_MM;
        $pdf->fillCornerSegments([
            [17.2, 0.0, 12.0, 2.4, 8.0, 7.4, 4.5, 12.4],
            [4.5, 12.4, 2.4, 15.2, 1.0, 17.2, 0.0, 18.4],
        ], self::ORANGE);
        $pdf->fillCornerSegments([
            [11.6, 0.0, 8.0, 1.8, 5.0, 5.4, 2.8, 8.6],
            [2.8, 8.6, 1.4, 10.4, 0.5, 11.6, 0.0, 12.4],
        ], self::RED);

        $orangeTop = [
            [0.0, 71.8, 6.0, 72.8, 14.0, 75.2, 24.0, 77.6],
            [24.0, 77.6, 32.0, 78.8, 40.0, 76.8, 46.0, 74.6],
            [46.0, 74.6, 50.0, 73.2, 52.4, 72.4, $w, 72.0],
        ];
        $redTop = [
            [0.0, 77.0, 8.0, 76.8, 16.0, 77.2, 26.0, 78.2],
            [26.0, 78.2, 36.0, 78.4, 44.0, 76.2, $w, 73.2],
        ];
        $navyTop = [
            [0.0, 81.8, 10.0, 81.6, 20.0, 80.8, 32.0, 79.6],
            [32.0, 79.6, 42.0, 77.6, 48.0, 75.2, $w, 73.6],
        ];
        $pdf->fillRibbonSegments($orangeTop, $redTop, self::ORANGE);
        $pdf->fillRibbonSegments($redTop, $navyTop, self::RED);
        $pdf->fillBelowSegments($navyTop, self::NAVY);
    }

    private function placePortrait(IdCardCanvas $pdf, float $x, float $y, float $w, float $h, ?string $photoPath): void
    {
        $pad = 1.25;
        $radius = 2.55;
        $pdf->fillRoundRect($x - $pad + 0.12, $y - $pad + 0.28, $w + ($pad * 2), $h + ($pad * 2), $radius, [176, 186, 204]);
        $pdf->fillRoundRect($x - $pad, $y - $pad, $w + ($pad * 2), $h + ($pad * 2), $radius, [214, 218, 224]);
        $pdf->fillRoundRect($x - $pad + 0.32, $y - $pad + 0.32, $w + ($pad * 2) - 0.64, $h + ($pad * 2) - 0.64, $radius - 0.22, self::WHITE);

        if ($photoPath === null) {
            $pdf->SetTextColor(...self::MUTED);
            $pdf->SetFont('Helvetica', 'B', 6);
            $pdf->SetXY($x, $y + ($h / 2) - 1.5);
            $pdf->Cell($w, 3, 'PHOTO', 0, 0, 'C');

            return;
        }

        $fitted = $this->fittedPhotoPng($photoPath, $w, $h);
        $pdf->clipRoundRect($x, $y, $w, $h, 2.35);
        try {
            $pdf->Image($fitted ?? $photoPath, $x, $y, $w, $h);
        } finally {
            $pdf->endClip();
            if ($fitted !== null && is_file($fitted)) {
                unlink($fitted);
            }
        }
    }

    private function styledName(\FPDF $pdf, string $name, float $y): void
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        if ($parts === []) {
            return;
        }

        $size = 14.0;
        $pdf->SetFont('Helvetica', 'B', $size);
        if (count($parts) === 1) {
            $label = $this->text(strtoupper($parts[0]));
            while ($size > 10.5 && $pdf->GetStringWidth($label) > self::WIDTH_MM - 8) {
                $size -= 0.5;
                $pdf->SetFont('Helvetica', 'B', $size);
            }
            $pdf->SetTextColor(...self::NAVY);
            $pdf->SetXY(3, $y);
            $pdf->Cell(self::WIDTH_MM - 6, 5.1, $label, 0, 1, 'C');

            return;
        }

        $last = (string) array_pop($parts);
        $first = implode(' ', $parts);
        $firstText = $this->text(strtoupper($first));
        $lastText = $this->text(strtoupper($last));
        $gap = 1.15;
        while ($size > 10.5 && ($pdf->GetStringWidth($firstText) + $gap + $pdf->GetStringWidth($lastText)) > self::WIDTH_MM - 8) {
            $size -= 0.5;
            $pdf->SetFont('Helvetica', 'B', $size);
        }
        $firstW = $pdf->GetStringWidth($firstText);
        $lastW = $pdf->GetStringWidth($lastText);
        $x = (self::WIDTH_MM - ($firstW + $gap + $lastW)) / 2;

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetXY($x, $y);
        $pdf->Cell($firstW, 5.1, $firstText, 0, 0, 'L');
        $pdf->SetTextColor(...self::ORANGE);
        $pdf->SetXY($x + $firstW + $gap, $y);
        $pdf->Cell($lastW, 5.1, $lastText, 0, 1, 'L');
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private function centerTracked(\FPDF $pdf, string $text, float $y, float $size, float $tracking, array $color, float $h): void
    {
        $pdf->SetFont('Helvetica', '', $size);
        $pdf->SetTextColor(...$color);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($chars === []) {
            return;
        }

        $widths = [];
        $width = 0.0;
        foreach ($chars as $char) {
            $charWidth = $pdf->GetStringWidth($char);
            $widths[] = $charWidth;
            $width += $charWidth;
        }
        $width += $tracking * (count($chars) - 1);
        $x = (self::WIDTH_MM - $width) / 2;
        foreach ($chars as $index => $char) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($widths[$index] + 0.4, $h, $char, 0, 0, 'L');
            $x += $widths[$index] + $tracking;
        }
        $pdf->SetY($y + $h);
    }

    private function iconRow(\FPDF $pdf, float $y, string $icon, string $label, string $value): float
    {
        $glyph = $this->iconPng($icon);
        try {
            $pdf->Image($glyph, 5.7, $y, 3.2, 3.2);
        } finally {
            if (is_file($glyph)) {
                unlink($glyph);
            }
        }

        $shown = trim($this->text($value));
        if ($shown === '') {
            $shown = '-';
        }

        $textY = $y + 0.42;
        $pdf->SetXY(10.1, $textY);
        $pdf->SetFont('Helvetica', 'B', 4.15);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Cell(15.6, 2.35, $label, 0, 0, 'L');
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('Helvetica', 'B', 5.1);
        $pdf->SetXY(25.9, $textY);
        $pdf->Cell(1.8, 2.35, ':', 0, 0, 'C');
        $pdf->SetFont('Helvetica', 'B', 5.25);
        $pdf->SetXY(28.2, $textY);
        $pdf->Cell(self::WIDTH_MM - 31.4, 2.35, $this->truncate($shown, 28), 0, 1, 'L');

        return $y + 3.85;
    }

    private function metaPair(\FPDF $pdf, float $y, float $x, float $right, string $icon, string $label, string $value): void
    {
        $glyph = $this->iconPng($icon);
        try {
            $pdf->Image($glyph, $x, $y + 0.85, 3.3, 3.3);
        } finally {
            if (is_file($glyph)) {
                unlink($glyph);
            }
        }

        $pdf->SetXY($x + 4.0, $y + 0.35);
        $pdf->SetFont('Helvetica', 'B', 3.5);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Cell($right - $x - 4.0, 1.7, $label, 0, 2, 'L');
        $pdf->SetFont('Helvetica', 'B', 5.15);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->Cell($right - $x - 4.0, 2.3, $this->truncate($this->text($value), 16), 0, 0, 'L');
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function frontAddressFooter(\FPDF $pdf, array $card): void
    {
        $address = trim($this->text($card['footerAddress'] ?? ''));
        if ($address === '') {
            return;
        }

        $pin = $this->iconPng('pin');
        try {
            $pdf->Image($pin, 4.6, 78.6, 3.15, 3.15);
        } finally {
            if (is_file($pin)) {
                unlink($pin);
            }
        }

        $pdf->SetTextColor(...self::WHITE);
        $pdf->SetFont('Helvetica', '', 4.35);
        $pdf->SetXY(8.6, 78.95);
        $pdf->MultiCell(self::WIDTH_MM - 12.6, 1.9, $this->truncate($address, 110), 0, 'L');
    }

    private function bulletRow(\FPDF $pdf, float $y, float $x, float $width, string $label, string $value): float
    {
        $pdf->SetFillColor(...self::ORANGE);
        $pdf->Rect($x + 0.25, $y + 1.55, 1.7, 1.7, 'F');

        $pdf->SetXY($x + 3.2, $y);
        $pdf->SetFont('Helvetica', 'B', 4.5);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Cell($width - 3.2, 1.9, $label, 0, 1, 'L');

        $pdf->SetXY($x + 3.2, $y + 2.0);
        $pdf->SetFont('Helvetica', 'B', 6.0);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->MultiCell($width - 3.2, 2.4, $this->text($value !== '' ? $value : '—'), 0, 'L');

        return max($pdf->GetY(), $y + 6.2) + 0.8;
    }

    private function photoFrame(\FPDF $pdf, float $x, float $y, float $w, float $h, ?string $photoPath): void
    {
        if ($photoPath === null) {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($x - 1.6, $y - 1.6, $w + 3.2, $h + 3.2, 'F');
            $pdf->SetTextColor(...self::MUTED);
            $pdf->SetFont('Helvetica', 'B', 6);
            $pdf->SetXY($x, $y + ($h / 2) - 1.5);
            $pdf->Cell($w, 3, 'PHOTO', 0, 0, 'C');

            return;
        }

        $framed = $this->roundedPhotoPng($photoPath, $w, $h);
        $pad = 1.7;
        try {
            $pdf->Image($framed ?? $photoPath, $x - $pad, $y - $pad, $w + ($pad * 2), $h + ($pad * 2));
        } finally {
            if ($framed !== null && is_file($framed)) {
                unlink($framed);
            }
        }
    }

    private function roundedPhotoPng(string $photoPath, float $frameWmm, float $frameHmm): ?string
    {
        $fitted = $this->fittedPhotoPng($photoPath, $frameWmm, $frameHmm);
        if ($fitted === null) {
            return null;
        }

        $photo = @imagecreatefromjpeg($fitted);
        @unlink($fitted);
        if ($photo === false) {
            return null;
        }

        $scale = 14;
        $innerW = (int) round($frameWmm * $scale);
        $innerH = (int) round($frameHmm * $scale);
        $pad = (int) round(1.7 * $scale);
        $radius = (int) round(2.4 * $scale);
        $canvasW = $innerW + ($pad * 2);
        $canvasH = $innerH + ($pad * 2);
        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $canvasW, $canvasH, $white);
        imagecopyresampled($canvas, $photo, $pad, $pad, 0, 0, $innerW, $innerH, imagesx($photo), imagesy($photo));
        $this->roundMask($canvas, $pad, $pad, $innerW, $innerH, $radius, $white);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'id-card-frame-'.bin2hex(random_bytes(6)).'.jpg';
        imagejpeg($canvas, $path, 92);
        imagedestroy($canvas);
        imagedestroy($photo);

        return $path;
    }

    private function fillRoundRect($img, int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        imagefilledrectangle($img, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($img, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        imagefilledellipse($img, $x + $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $w - $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $r, $y + $h - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $color);
    }

    private function roundMask($img, int $x, int $y, int $w, int $h, int $r, int $outside): void
    {
        for ($py = 0; $py < $r; $py++) {
            for ($px = 0; $px < $r; $px++) {
                $dx = $r - $px;
                $dy = $r - $py;
                if (($dx * $dx) + ($dy * $dy) > $r * $r) {
                    imagesetpixel($img, $x + $px, $y + $py, $outside);
                    imagesetpixel($img, $x + $w - 1 - $px, $y + $py, $outside);
                    imagesetpixel($img, $x + $px, $y + $h - 1 - $py, $outside);
                    imagesetpixel($img, $x + $w - 1 - $px, $y + $h - 1 - $py, $outside);
                }
            }
        }
    }

    /**
     * Center-crop the source photo to the frame aspect ratio so FPDF never stretches it.
     */
    private function fittedPhotoPng(string $photoPath, float $frameWmm, float $frameHmm): ?string
    {
        $info = @getimagesize($photoPath);
        if (! is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return null;
        }

        $src = match ($info[2] ?? 0) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($photoPath),
            IMAGETYPE_PNG => @imagecreatefrompng($photoPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($photoPath) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($photoPath),
            default => false,
        };

        if ($src === false) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $targetRatio = $frameWmm / max(0.01, $frameHmm);
        $srcRatio = $srcW / max(1, $srcH);

        if ($srcRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $cropX = (int) max(0, floor(($srcW - $cropW) / 2));
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $cropX = 0;
            $cropY = (int) max(0, floor(($srcH - $cropH) / 2));
        }

        $cropW = max(1, min($cropW, $srcW - $cropX));
        $cropH = max(1, min($cropH, $srcH - $cropY));

        $outW = max(220, (int) round($frameWmm * 12));
        $outH = max(260, (int) round($frameHmm * 12));
        $dst = imagecreatetruecolor($outW, $outH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $outW, $outH, $white);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $outW, $outH, $cropW, $cropH);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'id-card-photo-'.bin2hex(random_bytes(8)).'.jpg';
        imagejpeg($dst, $path, 92);
        imagedestroy($dst);
        imagedestroy($src);

        return $path;
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

    private function stampRoundedPhoto(string $artPath, ?string $photoPath, float $x, float $y, float $w, float $h, float $pad): void
    {
        $art = @imagecreatefrompng($artPath);
        if ($art === false) {
            return;
        }

        $scale = imagesx($art) / self::WIDTH_MM;
        $shadow = (int) round(0.42 * $scale);
        $outerX = (int) round(($x - $pad) * $scale);
        $outerY = (int) round(($y - $pad) * $scale);
        $outerW = (int) round(($w + ($pad * 2)) * $scale);
        $outerH = (int) round(($h + ($pad * 2)) * $scale);
        $radius = (int) round(3.1 * $scale);
        $white = imagecolorallocate($art, 255, 255, 255);
        $shade = imagecolorallocate($art, 15, 28, 62);
        $gray = imagecolorallocate($art, 206, 212, 220);
        $this->fillRoundRect($art, $outerX + (int) ($shadow * 0.35), $outerY + $shadow, $outerW, $outerH, $radius, $shade);
        $this->fillRoundRect($art, $outerX, $outerY, $outerW, $outerH, $radius, $gray);
        $inset = max(2, (int) round(0.32 * $scale));
        $this->fillRoundRect($art, $outerX + $inset, $outerY + $inset, $outerW - ($inset * 2), $outerH - ($inset * 2), max(6, $radius - $inset), $white);

        if ($photoPath !== null) {
            $fitted = $this->fittedPhotoPng($photoPath, $w, $h);
            $photo = $fitted !== null ? @imagecreatefromjpeg($fitted) : false;
            if ($fitted !== null && is_file($fitted)) {
                unlink($fitted);
            }
            if ($photo !== false) {
                $innerX = (int) round($x * $scale);
                $innerY = (int) round($y * $scale);
                $innerW = (int) round($w * $scale);
                $innerH = (int) round($h * $scale);
                $layer = imagecreatetruecolor($innerW, $innerH);
                $layerWhite = imagecolorallocate($layer, 255, 255, 255);
                imagefilledrectangle($layer, 0, 0, $innerW, $innerH, $layerWhite);
                imagecopyresampled($layer, $photo, 0, 0, 0, 0, $innerW, $innerH, imagesx($photo), imagesy($photo));
                $this->roundMask($layer, 0, 0, $innerW, $innerH, (int) round(2.35 * $scale), $layerWhite);
                imagecopy($art, $layer, $innerX, $innerY, 0, 0, $innerW, $innerH);
                imagedestroy($layer);
                imagedestroy($photo);
            }
        }

        imagepng($art, $artPath);
        imagedestroy($art);
    }

    private function stampQrFrame(string $artPath, float $x, float $y, float $w, float $h): void
    {
        $art = @imagecreatefrompng($artPath);
        if ($art === false) {
            return;
        }

        $scale = imagesx($art) / self::WIDTH_MM;
        $px = (int) round($x * $scale);
        $py = (int) round($y * $scale);
        $pw = (int) round($w * $scale);
        $ph = (int) round($h * $scale);
        $radius = (int) round(2.05 * $scale);
        $orange = imagecolorallocate($art, self::ORANGE[0], self::ORANGE[1], self::ORANGE[2]);
        $white = imagecolorallocate($art, 255, 255, 255);
        $shade = imagecolorallocate($art, 232, 214, 204);
        $shadow = (int) round(0.28 * $scale);
        $this->fillRoundRect($art, $px, $py + $shadow, $pw, $ph, $radius, $shade);
        $this->fillRoundRect($art, $px, $py, $pw, $ph, $radius, $orange);
        $inset = max(3, (int) round(0.55 * $scale));
        $this->fillRoundRect($art, $px + $inset, $py + $inset, $pw - ($inset * 2), $ph - ($inset * 2), max(6, $radius - $inset), $white);
        imagepng($art, $artPath);
        imagedestroy($art);
    }

    private function wavePng(string $side): string
    {
        $scale = 12;
        $w = (int) round(self::WIDTH_MM * $scale);
        $h = (int) round(self::HEIGHT_MM * $scale);
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        $navy = imagecolorallocate($img, self::NAVY[0], self::NAVY[1], self::NAVY[2]);
        $navyDeep = imagecolorallocate($img, self::NAVY_DEEP[0], self::NAVY_DEEP[1], self::NAVY_DEEP[2]);
        $red = imagecolorallocate($img, self::RED[0], self::RED[1], self::RED[2]);
        $orange = imagecolorallocate($img, self::ORANGE[0], self::ORANGE[1], self::ORANGE[2]);
        $orangeSoft = imagecolorallocate($img, self::ORANGE_SOFT[0], self::ORANGE_SOFT[1], self::ORANGE_SOFT[2]);

        if ($side === 'front') {
            $this->paintFrontArt($img, $w, $h, $navy, $navyDeep, $orange, $red, $orangeSoft);
        } else {
            $this->paintBackArt($img, $w, $h, $navy, $orange, $red, $orangeSoft);
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'id-card-wave-'.$side.'-'.bin2hex(random_bytes(6)).'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function paintFrontArt($img, int $w, int $h, int $navy, int $deep, int $orange, int $red, int $soft): void
    {
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w, $h, $white);

        $navyEdge = [[0.00, 0.232], [0.12, 0.248], [0.26, 0.272], [0.42, 0.294], [0.58, 0.300], [0.74, 0.286], [0.88, 0.252], [1.00, 0.206]];
        $orangeBottom = [[0.00, 0.338], [0.14, 0.322], [0.30, 0.312], [0.48, 0.314], [0.66, 0.310], [0.82, 0.296], [1.00, 0.286]];
        $redBottom = [[0.00, 0.438], [0.14, 0.408], [0.30, 0.376], [0.48, 0.356], [0.66, 0.340], [0.82, 0.322], [1.00, 0.296]];
        $echoTop = [[0.00, 0.438], [0.16, 0.404], [0.34, 0.370], [0.52, 0.348]];
        $echoBottom = [[0.00, 0.454], [0.16, 0.416], [0.34, 0.380], [0.52, 0.352]];

        $this->fillToCurve($img, $w, $h, $navyEdge, true, $navy);
        $this->pinstripes($img, $w, $h, $navyEdge, $deep);
        $this->ribbon($img, $w, $h, $navyEdge, $orangeBottom, $orange);
        $this->ribbon($img, $w, $h, $orangeBottom, $redBottom, $red);
        $this->ribbon($img, $w, $h, $echoTop, $echoBottom, $navy);

        $footerOrange = [[0.00, 0.856], [0.18, 0.846], [0.38, 0.840], [0.58, 0.836], [0.76, 0.822], [0.90, 0.804], [1.00, 0.784]];
        $footerNavy = [[0.00, 0.876], [0.18, 0.864], [0.38, 0.858], [0.58, 0.854], [0.76, 0.840], [0.90, 0.824], [1.00, 0.808]];
        $this->ribbon($img, $w, $h, $footerOrange, $footerNavy, $orange);
        $this->ribbon($img, $w, $h, [[0.00, 0.866], [0.20, 0.854], [0.42, 0.848], [0.62, 0.844]], [[0.00, 0.876], [0.20, 0.864], [0.42, 0.858], [0.62, 0.854]], $red);
        $this->fillToCurve($img, $w, $h, $footerNavy, false, $navy);
        $this->roundCardCorners($img, $w, $h, (int) (0.055 * $w), $white);
    }

    private function paintBackArt($img, int $w, int $h, int $navy, int $orange, int $red, int $soft): void
    {
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w, $h, $white);

        $this->ribbon(
            $img,
            $w,
            $h,
            [[0.00, 0.00], [0.08, 0.00], [0.16, 0.00], [0.24, 0.00]],
            [[0.00, 0.168], [0.08, 0.118], [0.16, 0.062], [0.24, 0.00]],
            $orange
        );
        $this->ribbon(
            $img,
            $w,
            $h,
            [[0.00, 0.00], [0.06, 0.00], [0.12, 0.00], [0.18, 0.00]],
            [[0.00, 0.108], [0.06, 0.074], [0.12, 0.036], [0.18, 0.00]],
            $red
        );

        $footerOrange = [[0.00, 0.812], [0.12, 0.828], [0.26, 0.852], [0.42, 0.878], [0.58, 0.886], [0.74, 0.868], [0.88, 0.842], [1.00, 0.822]];
        $footerRed = [[0.00, 0.868], [0.18, 0.872], [0.36, 0.888], [0.54, 0.896], [0.72, 0.878], [0.88, 0.854], [1.00, 0.838]];
        $footerNavy = [[0.00, 0.924], [0.20, 0.916], [0.40, 0.918], [0.60, 0.904], [0.78, 0.878], [1.00, 0.858]];
        $redTop = [[0.00, 0.868], [0.18, 0.872], [0.36, 0.888], [0.54, 0.896]];
        $redBottom = [[0.00, 0.924], [0.18, 0.916], [0.36, 0.918], [0.54, 0.902]];
        $this->ribbon($img, $w, $h, $footerOrange, $footerRed, $orange);
        $this->ribbon($img, $w, $h, $redTop, $redBottom, $red);
        $this->fillToCurve($img, $w, $h, $footerNavy, false, $navy);
        $this->roundCardCorners($img, $w, $h, (int) (0.055 * $w), $white);
    }

    private function roundCardCorners($img, int $w, int $h, int $r, int $color): void
    {
        $r = max(8, $r);
        for ($y = 0; $y < $r; $y++) {
            for ($x = 0; $x < $r; $x++) {
                $dx = $r - $x;
                $dy = $r - $y;
                if (($dx * $dx) + ($dy * $dy) > $r * $r) {
                    imagesetpixel($img, $x, $y, $color);
                    imagesetpixel($img, $w - 1 - $x, $y, $color);
                    imagesetpixel($img, $x, $h - 1 - $y, $color);
                    imagesetpixel($img, $w - 1 - $x, $h - 1 - $y, $color);
                }
            }
        }
    }

    private function iconPng(string $kind): string
    {
        $size = 128;
        $img = imagecreatetruecolor($size, $size);
        imageantialias($img, true);
        imagesavealpha($img, true);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $clear);
        $orange = imagecolorallocate($img, self::ORANGE[0], self::ORANGE[1], self::ORANGE[2]);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledellipse($img, 64, 64, 118, 118, $orange);
        imagesetthickness($img, 6);

        if ($kind === 'mail') {
            imagerectangle($img, 36, 46, 92, 84, $white);
            imageline($img, 36, 48, 64, 66, $white);
            imageline($img, 92, 48, 64, 66, $white);
            imageline($img, 36, 82, 54, 68, $white);
            imageline($img, 92, 82, 74, 68, $white);
        } elseif ($kind === 'drop') {
            imagefilledellipse($img, 64, 74, 36, 36, $white);
            imagefilledpolygon($img, [64, 30, 46, 68, 82, 68], $white);
        } elseif ($kind === 'calendar') {
            imagerectangle($img, 36, 42, 92, 92, $white);
            imageline($img, 36, 56, 92, 56, $white);
            imagefilledrectangle($img, 48, 32, 56, 50, $white);
            imagefilledrectangle($img, 72, 32, 80, 50, $white);
        } elseif ($kind === 'pin') {
            imagefilledellipse($img, 64, 52, 36, 36, $white);
            imagefilledpolygon($img, [64, 98, 46, 62, 82, 62], $white);
            imagefilledellipse($img, 64, 52, 14, 14, $orange);
        } elseif ($kind === 'card') {
            imagerectangle($img, 30, 40, 98, 88, $white);
            imagefilledrectangle($img, 42, 52, 62, 64, $white);
            imageline($img, 68, 56, 88, 56, $white);
            imageline($img, 42, 74, 88, 74, $white);
        } else {
            imagefilledellipse($img, 64, 50, 24, 24, $white);
            imagefilledellipse($img, 64, 92, 46, 36, $white);
            imagefilledrectangle($img, 40, 92, 88, 110, $orange);
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'id-card-icon-'.bin2hex(random_bytes(6)).'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function scanPill(IdCardCanvas $pdf): void
    {
        $pillW = 33.2;
        $pillH = 4.6;
        $pillX = (self::WIDTH_MM - $pillW) / 2;
        $pillY = 42.35;
        $label = 'SCAN TO VERIFY EMPLOYEE';
        $size = 5.2;

        $pdf->fillRoundRect($pillX, $pillY, $pillW, $pillH, $pillH / 2, self::NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', $size);
        while ($size > 4.0 && $pdf->GetStringWidth($label) > ($pillW - 2.2)) {
            $size -= 0.2;
            $pdf->SetFont('Helvetica', 'B', $size);
        }

        $textH = 2.0;
        $pdf->SetXY($pillX, $pillY + (($pillH - $textH) / 2));
        $pdf->Cell($pillW, $textH, $label, 0, 0, 'C');
    }

    private function signaturePng(): ?string
    {
        $w = 680;
        $h = 170;
        $img = imagecreatetruecolor($w, $h);
        imageantialias($img, true);
        imagesavealpha($img, true);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $clear);
        $ink = imagecolorallocate($img, 16, 42, 112);
        $this->bezierStroke($img, [
            [[78, 118], [36, 48], [168, 18], [154, 124]],
            [[154, 124], [140, 72], [230, 58], [268, 108]],
            [[268, 108], [330, 150], [520, 42], [630, 96]],
        ], $ink);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'id-card-sign-'.bin2hex(random_bytes(6)).'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    /**
     * @param  list<array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}, 2: array{0: int, 1: int}, 3: array{0: int, 1: int}}>  $segments
     */
    private function bezierStroke($img, array $segments, int $ink): void
    {
        foreach ($segments as [$p0, $p1, $p2, $p3]) {
            for ($s = 0; $s <= 40; $s++) {
                $t = $s / 40;
                $u = 1 - $t;
                $x = ($u * $u * $u * $p0[0]) + (3 * $u * $u * $t * $p1[0]) + (3 * $u * $t * $t * $p2[0]) + ($t * $t * $t * $p3[0]);
                $y = ($u * $u * $u * $p0[1]) + (3 * $u * $u * $t * $p1[1]) + (3 * $u * $t * $t * $p2[1]) + ($t * $t * $t * $p3[1]);
                imagefilledellipse($img, (int) round($x), (int) round($y), 7, 7, $ink);
            }
        }
    }

    /**
     * @param  list<array{0: int, 1: int}>  $points
     */
    private function inkStroke($img, array $points, int $ink): void
    {
        $count = count($points);
        for ($i = 0; $i < $count - 1; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[$i + 1];
            $steps = max(6, (int) hypot($x2 - $x1, $y2 - $y1));
            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / $steps;
                imagefilledellipse(
                    $img,
                    (int) round($x1 + (($x2 - $x1) * $t)),
                    (int) round($y1 + (($y2 - $y1) * $t)),
                    8,
                    8,
                    $ink
                );
            }
        }
    }

    private function logoOnNavy(?string $logoPath): ?string
    {
        return $this->prepareLogo($logoPath, true);
    }

    private function trimLogo(?string $logoPath): ?string
    {
        return $this->prepareLogo($logoPath, false);
    }

    private function prepareLogo(?string $logoPath, bool $onNavy): ?string
    {
        if ($logoPath === null || ! is_file($logoPath)) {
            return null;
        }

        $src = @imagecreatefrompng($logoPath);
        if ($src === false) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $minX = $srcW;
        $minY = $srcH;
        $maxX = 0;
        $maxY = 0;
        for ($y = 0; $y < $srcH; $y++) {
            for ($x = 0; $x < $srcW; $x++) {
                $rgba = imagecolorat($src, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                if ($alpha < 20 && ! ($r > 245 && $g > 245 && $b > 245)) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX <= $minX || $maxY <= $minY) {
            imagedestroy($src);

            return null;
        }

        $w = $maxX - $minX + 1;
        $h = $maxY - $minY + 1;
        $dst = imagecreatetruecolor($w, $h);
        $navy = imagecolorallocate($dst, self::NAVY[0], self::NAVY[1], self::NAVY[2]);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $onNavy ? $navy : $white);
        imagecopy($dst, $src, 0, 0, $minX, $minY, $w, $h);

        if ($onNavy) {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgba = imagecolorat($dst, $x, $y);
                    $r = ($rgba >> 16) & 0xFF;
                    $g = ($rgba >> 8) & 0xFF;
                    $b = $rgba & 0xFF;
                    $isOrange = $r > 150 && $g > 60 && $g < 190 && $b < 120 && $r > $b + 40;
                    if (! $isOrange) {
                        imagesetpixel($dst, $x, $y, ($r > 242 && $g > 242 && $b > 242) ? $navy : $white);
                    }
                }
            }
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'id-card-logo-'.bin2hex(random_bytes(6)).'.png';
        imagepng($dst, $path);
        imagedestroy($dst);
        imagedestroy($src);

        return $path;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $knots
     * @return list<array{0: int, 1: int}>
     */
    private function curvePoints(array $knots, int $w, int $h): array
    {
        $pts = [];
        $count = count($knots);
        $steps = 42;
        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $knots[max(0, $i - 1)];
            $p1 = $knots[$i];
            $p2 = $knots[$i + 1];
            $p3 = $knots[min($count - 1, $i + 2)];
            for ($s = 0; $s < $steps; $s++) {
                $t = $s / $steps;
                $t2 = $t * $t;
                $t3 = $t2 * $t;
                $x = 0.5 * ((2 * $p1[0]) + (-$p0[0] + $p2[0]) * $t + (2 * $p0[0] - 5 * $p1[0] + 4 * $p2[0] - $p3[0]) * $t2 + (-$p0[0] + 3 * $p1[0] - 3 * $p2[0] + $p3[0]) * $t3);
                $y = 0.5 * ((2 * $p1[1]) + (-$p0[1] + $p2[1]) * $t + (2 * $p0[1] - 5 * $p1[1] + 4 * $p2[1] - $p3[1]) * $t2 + (-$p0[1] + 3 * $p1[1] - 3 * $p2[1] + $p3[1]) * $t3);
                $pts[] = [(int) round(max(0, min(1, $x)) * ($w - 1)), (int) round(max(0, min(1, $y)) * ($h - 1))];
            }
        }
        $last = $knots[$count - 1];
        $pts[] = [(int) round($last[0] * ($w - 1)), (int) round($last[1] * ($h - 1))];

        return $pts;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $top
     * @param  list<array{0: float, 1: float}>  $bottom
     */
    private function ribbon($img, int $w, int $h, array $top, array $bottom, int $color): void
    {
        $poly = array_merge($this->curvePoints($top, $w, $h), array_reverse($this->curvePoints($bottom, $w, $h)));
        $flat = [];
        foreach ($poly as [$x, $y]) {
            $flat[] = $x;
            $flat[] = $y;
        }
        if (count($flat) >= 6) {
            imagefilledpolygon($img, $flat, $color);
        }
    }

    /**
     * @param  list<array{0: float, 1: float}>  $curve
     */
    private function fillToCurve($img, int $w, int $h, array $curve, bool $above, int $color): void
    {
        $edge = $this->curvePoints($curve, $w, $h);
        $flat = $above ? [0, 0, $w - 1, 0] : [];
        $points = $above ? array_reverse($edge) : $edge;
        foreach ($points as [$x, $y]) {
            $flat[] = $x;
            $flat[] = $y;
        }
        if (! $above) {
            $flat[] = $w - 1;
            $flat[] = $h - 1;
            $flat[] = 0;
            $flat[] = $h - 1;
        }
        imagefilledpolygon($img, $flat, $color);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $curve
     */
    private function pinstripes($img, int $w, int $h, array $curve, int $color): void
    {
        $edge = $this->curvePoints($curve, $w, $h);
        $limit = array_fill(0, $w, (int) (0.2 * $h));
        foreach ($edge as [$x, $y]) {
            if ($x >= 0 && $x < $w) {
                $limit[$x] = $y;
            }
        }
        for ($x = 0; $x < $w; $x += 8) {
            imageline($img, $x, 0, $x, max(0, $limit[$x] - 2), $color);
        }
    }

    private function fillWaveBand($img, int $w, int $top, int $bottom, int $color, int $deep, bool $topHeavy): void
    {
        for ($x = 0; $x < $w; $x++) {
            $t = $x / max(1, $w - 1);
            $curve = (int) round(sin($t * M_PI) * ($topHeavy ? 0.10 : 0.12) * ($bottom - $top));
            $edge = $topHeavy
                ? $bottom - $curve - (int) round($t * 0.08 * ($bottom - $top))
                : $top + $curve + (int) round((1 - $t) * 0.08 * ($bottom - $top));

            if ($topHeavy) {
                imageline($img, $x, 0, $x, max(0, $edge), $color);
                if ($x % 17 === 0) {
                    imageline($img, $x, 0, $x, (int) ($edge * 0.35), $deep);
                }
            } else {
                $maxY = imagesy($img) - 1;
                imageline($img, $x, min($maxY, max(0, $edge)), $x, $maxY, $color);
            }
        }
    }

    private function drawSwoosh($img, int $w, int $h, float $startY, float $endY, int $color, float $thickness): void
    {
        $band = max(4, (int) round($thickness * $h));
        for ($x = 0; $x < $w; $x++) {
            $t = $x / max(1, $w - 1);
            $y = (int) round(($startY + ($endY - $startY) * $t + sin(($t * 1.35 + 0.15) * M_PI) * 0.045) * $h);
            imagefilledellipse($img, $x, $y, $band, (int) ($band * 0.72), $color);
        }
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

    private function formatDate(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        $ts = strtotime($raw);

        return $ts === false ? $raw : date('j M Y', $ts);
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
