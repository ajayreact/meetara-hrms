<?php

namespace App\Services\Hrms;

use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;

class OfferLetterPdf
{
    /**
     * @param  array{top?: int, right?: int, bottom?: int, left?: int}  $margins
     * @param  array<string, mixed>  $layout
     */
    public function render(string $body, ?string $jpegPath = null, array $margins = [], array $layout = []): string
    {
        $layout = array_merge([
            'image' => $jpegPath,
            'pdf' => null,
            'margins' => $margins,
            'pageWidth' => null,
            'pageHeight' => null,
            'signature' => ['enabled' => false, 'path' => null],
        ], $layout);
        $layout['margins'] = array_merge($margins, is_array($layout['margins'] ?? null) ? $layout['margins'] : []);
        $signature = is_array($layout['signature'] ?? null) ? $layout['signature'] : [];

        if (($signature['enabled'] ?? false) && $this->needsSignature($body)) {
            $path = $signature['path'] ?? null;

            if (! is_string($path) || ! is_file($path)) {
                throw ValidationException::withMessages([
                    'letter' => 'Automatic HR signature is turned on, but no active signature image is on file. Upload a signature or turn automatic placement off.',
                ]);
            }
        }

        $pdf = new AppointmentStamp('P', 'pt');
        $pdf->SetCompression(false);
        $pdf->SetAutoPageBreak(false);
        $page = $this->pageSize($layout);
        $pdf->usePageSize($page['width'], $page['height']);
        $background = $this->prepareBackground($pdf, $layout, $page);
        $pdf->backgroundTemplate = $background['template'];
        $pdf->backgroundImage = $background['image'];
        $headerReserve = max(0, (int) ($layout['headerHeight'] ?? 0));
        $footerReserve = max(0, (int) ($layout['footerHeight'] ?? 0));
        $left = max(36, (int) ($layout['margins']['left'] ?? 48));
        $top = max(36, (int) ($layout['margins']['top'] ?? 72), $headerReserve);
        $right = max(36, (int) ($layout['margins']['right'] ?? 48));
        $bottom = max(36, (int) ($layout['margins']['bottom'] ?? 56), $footerReserve);
        $pdf->headerReserve = (float) $headerReserve;
        $pdf->footerReserve = (float) $footerReserve;
        $pdf->SetMargins($left, $top, $right);
        $pdf->preparedBottom = $bottom;
        $pdf->AddPage($page['orientation'], [$page['width'], $page['height']]);
        $pdf->SetAutoPageBreak(true, $bottom);
        $document = $this->document($body);

        if ($document !== null) {
            $this->writeDocument($pdf, $document, $signature);
        } elseif (OfferLetterMarkup::looksLikeHtml($body)) {
            (new HtmlLetterWriter($pdf, $signature, fn () => $this->placeSignature($pdf, $signature)))->write($body);
        } else {
            $this->writePlain($pdf, $body);
        }

        return $pdf->Output('S');
    }

    /**
     * @return array{pages: int, width: float, height: float, usable: bool, reason: string}
     */
    public function inspectPdf(string $path): array
    {
        $empty = ['pages' => 0, 'width' => 0.0, 'height' => 0.0, 'usable' => false, 'reason' => 'missing'];

        if (! is_file($path)) {
            return $empty;
        }

        try {
            $pdf = new Fpdi('P', 'pt');
            $pages = $pdf->setSourceFile($path);
            $template = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($template);
            $raw = (string) file_get_contents($path);
            $completed = stripos($raw, 'APPOINTMENT LETTER') !== false;
            $reason = $completed ? 'completed' : ($pages === 1 ? 'usable' : 'multipage');

            return [
                'pages' => $pages,
                'width' => (float) $size['width'],
                'height' => (float) $size['height'],
                'usable' => $reason === 'usable',
                'reason' => $reason,
            ];
        } catch (\Throwable) {
            return ['pages' => 0, 'width' => 0.0, 'height' => 0.0, 'usable' => false, 'reason' => 'parser'];
        }
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{width: float, height: float, orientation: string}
     */
    private function pageSize(array $layout): array
    {
        $width = is_numeric($layout['pageWidth'] ?? null) ? (float) $layout['pageWidth'] : 595.28;
        $height = is_numeric($layout['pageHeight'] ?? null) ? (float) $layout['pageHeight'] : 841.89;
        $pdf = $layout['pdf'] ?? null;

        if (is_string($pdf) && is_file($pdf)) {
            $inspected = $this->inspectPdf($pdf);

            if ($inspected['usable']) {
                $width = $inspected['width'];
                $height = $inspected['height'];
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'orientation' => $width > $height ? 'L' : 'P',
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array{width: float, height: float, orientation: string}  $page
     * @return array{template: mixed, image: ?string}
     */
    private function prepareBackground(AppointmentStamp $pdf, array $layout, array $page): array
    {
        $source = $layout['pdf'] ?? null;

        if (is_string($source) && is_file($source)) {
            $inspected = $this->inspectPdf($source);

            if ($inspected['usable']) {
                $pdf->setSourceFile($source);

                return ['template' => $pdf->importPage(1), 'image' => null];
            }
        }

        $image = $layout['image'] ?? null;

        if (is_string($image) && is_file($image)) {
            $pdf->imageBox = $this->containedBox($image, $page['width'], $page['height']);

            return ['template' => null, 'image' => $image];
        }

        return ['template' => null, 'image' => null];
    }

    /**
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function containedBox(string $path, float $pageWidth, float $pageHeight): ?array
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        $scale = min($pageWidth / $info[0], $pageHeight / $info[1]);

        $width = $info[0] * $scale;
        $height = $info[1] * $scale;

        return [
            'x' => ($pageWidth - $width) / 2,
            'y' => ($pageHeight - $height) / 2,
            'w' => $width,
            'h' => $height,
        ];
    }

    /**
     * @return array{kind: string, blocks: list<array<string, mixed>>}|null
     */
    private function document(string $body): ?array
    {
        $decoded = json_decode(ltrim($body), true);

        if (! is_array($decoded) || ($decoded['kind'] ?? '') !== 'appointment' || ! is_array($decoded['blocks'] ?? null)) {
            return null;
        }

        return $decoded;
    }

    private function needsSignature(string $body): bool
    {
        return str_contains($body, '"type":"closing"')
            || str_contains($body, '"type": "closing"')
            || str_contains($body, 'data-signature="hr"');
    }

    private function writePlain(AppointmentStamp $pdf, string $body): void
    {
        $pdf->SetFont('Helvetica', '', 11);
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];

        foreach ($lines as $line) {
            $pdf->MultiCell(0, 14, $this->latin($line === '' ? ' ' : $line), 0, 'L');
        }
    }

    /**
     * @param  array{kind: string, blocks: list<array<string, mixed>>}  $document
     * @param  array<string, mixed>  $signature
     */
    private function writeDocument(AppointmentStamp $pdf, array $document, array $signature): void
    {
        foreach ($document['blocks'] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? 'paragraph');

            if ($type === 'break') {
                $pdf->AddPage();

                continue;
            }

            if ($type === 'closing') {
                $this->writeClosing($pdf, $block, $signature);

                continue;
            }

            if ($type === 'acceptance') {
                $this->writeAcceptance($pdf, $block);

                continue;
            }

            if ($type === 'salary') {
                $this->writeSalary($pdf, $block);

                continue;
            }

            if ($type === 'roman' || $type === 'bullets') {
                $this->writeList($pdf, $block, $type === 'roman');

                continue;
            }

            $this->writeBlock($pdf, $block);
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function writeBlock(AppointmentStamp $pdf, array $block): void
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        $size = $this->size($block, $type === 'title' ? 16 : ($type === 'clause' ? 11 : 10));
        $align = $this->align($block, $type === 'title' ? 'C' : 'L');
        $style = $this->style($block, $type === 'title' || $type === 'clause');
        $pdf->Ln($type === 'title' ? 2 : 3);

        if ($type === 'clause') {
            $heading = trim((string) ($block['number'] ?? '').'. '.(string) ($block['heading'] ?? ''));
            $pdf->SetFont('Helvetica', 'B', $size);
            $pdf->MultiCell(0, $size + 3, $this->latin($heading), 0, 'L');
            $body = trim((string) ($block['body'] ?? ''));

            if ($body !== '') {
                $pdf->SetFont('Helvetica', '', 10);
                $pdf->MultiCell(0, 13, $this->latin($body), 0, 'L');
            }

            return;
        }

        if ($type === 'subclause') {
            $pdf->SetFont('Helvetica', 'B', $size);
            $pdf->MultiCell(0, 13, $this->latin(trim((string) ($block['marker'] ?? '').' '.(string) ($block['heading'] ?? ''))), 0, 'L');
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->MultiCell(0, 13, $this->latin((string) ($block['body'] ?? '')), 0, 'L');

            return;
        }

        $text = (string) ($block['text'] ?? '');

        if ($text === '') {
            return;
        }

        $pdf->SetFont('Helvetica', $style, $size);
        $pdf->MultiCell(0, $size + 3, $this->latin($text), 0, $align);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function writeList(AppointmentStamp $pdf, array $block, bool $roman): void
    {
        $items = is_array($block['items'] ?? null) ? $block['items'] : [];
        $pdf->SetFont('Helvetica', '', 10);
        $markers = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];

        foreach (array_values($items) as $index => $item) {
            $marker = $roman ? ($markers[$index] ?? (string) ($index + 1)) : '-';
            $pdf->MultiCell(0, 13, $this->latin($marker.'. '.(string) $item), 0, 'L');
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function writeSalary(AppointmentStamp $pdf, array $block): void
    {
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 11);

        foreach (is_array($block['lines'] ?? null) ? $block['lines'] : [] as $line) {
            $pdf->MultiCell(0, 14, $this->latin((string) $line), 0, 'L');
        }

        $pdf->SetFont('Helvetica', '', 10);

        foreach (is_array($block['notes'] ?? null) ? $block['notes'] : [] as $note) {
            $pdf->Ln(2);
            $pdf->MultiCell(0, 13, $this->latin((string) $note), 0, 'L');
        }
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $signature
     */
    private function writeClosing(AppointmentStamp $pdf, array $block, array $signature): void
    {
        $lines = array_values(is_array($block['lines'] ?? null) ? $block['lines'] : []);
        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->MultiCell(0, 14, $this->latin((string) ($lines[0] ?? 'With warm regards,')), 0, 'L');
        $pdf->MultiCell(0, 14, $this->latin((string) ($lines[1] ?? '')), 0, 'L');

        if (($signature['enabled'] ?? false) && is_string($signature['path'] ?? null) && is_file($signature['path'])) {
            $this->placeSignature($pdf, $signature);
        }

        $pdf->SetFont('Helvetica', '', 11);

        foreach (array_slice($lines, 2) as $line) {
            $pdf->MultiCell(0, 14, $this->latin((string) $line), 0, 'L');
        }
    }

    /**
     * @param  array<string, mixed>  $signature
     */
    private function placeSignature(AppointmentStamp $pdf, array $signature): void
    {
        $file = $this->signatureFile((string) $signature['path']);
        $info = @getimagesize($file);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ValidationException::withMessages(['letter' => 'The HR signature image could not be read. Upload a PNG or JPG signature.']);
        }

        $maxWidth = max(40, min(240, (float) ($signature['maxWidth'] ?? 140)));
        $maxHeight = max(16, min(120, (float) ($signature['maxHeight'] ?? 48)));
        $scale = min($maxWidth / $info[0], $maxHeight / $info[1]);
        $width = $info[0] * $scale;
        $height = $info[1] * $scale;
        $before = max(0, min(40, (float) ($signature['before'] ?? 8)));
        $after = max(0, min(40, (float) ($signature['after'] ?? 6)));
        $pdf->Ln($before);
        $limit = $pdf->GetPageHeight() - $pdf->preparedBottom;

        if ($pdf->GetY() + $height > $limit) {
            $pdf->AddPage();
        }

        $content = $pdf->GetPageWidth() - $pdf->contentLeft() - $pdf->contentRight();
        $align = (string) ($signature['align'] ?? 'left');
        $x = match ($align) {
            'center' => $pdf->contentLeft() + max(0, ($content - $width) / 2),
            'right' => $pdf->GetPageWidth() - $pdf->contentRight() - $width,
            default => $pdf->contentLeft(),
        };
        $y = $pdf->GetY();
        $pdf->Image($file, $x, $y, $width, $height);
        $pdf->SetY($y + $height + $after);

        if ($file !== $signature['path'] && is_file($file)) {
            @unlink($file);
        }
    }

    private function signatureFile(string $path): string
    {
        $info = @getimagesize($path);

        if ($info === false || ($info['mime'] ?? '') === 'image/jpeg' || ! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return $path;
        }

        $source = @imagecreatefromstring((string) file_get_contents($path));

        if ($source === false) {
            return $path;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            imagedestroy($source);

            return $path;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagealphablending($canvas, true);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($source, $x, $y);
                $alpha = ($pixel >> 24) & 0x7F;
                $red = ($pixel >> 16) & 0xFF;
                $green = ($pixel >> 8) & 0xFF;
                $blue = $pixel & 0xFF;
                $cover = 1 - ($alpha / 127);
                $color = imagecolorallocate(
                    $canvas,
                    (int) round(($red * $cover) + (255 * (1 - $cover))),
                    (int) round(($green * $cover) + (255 * (1 - $cover))),
                    (int) round(($blue * $cover) + (255 * (1 - $cover))),
                );
                imagesetpixel($canvas, $x, $y, $color);
            }
        }
        $temporary = tempnam(sys_get_temp_dir(), 'sig');
        $jpeg = $temporary === false ? $path : $temporary.'.jpg';

        if ($temporary !== false) {
            @unlink($temporary);
            imagejpeg($canvas, $jpeg, 92);
        }

        imagedestroy($source);
        imagedestroy($canvas);

        return is_file($jpeg) ? $jpeg : $path;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function writeAcceptance(AppointmentStamp $pdf, array $block): void
    {
        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->MultiCell(0, 16, $this->latin((string) ($block['heading'] ?? 'EMPLOYEE ACCEPTANCE')), 0, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 13, $this->latin((string) ($block['body'] ?? '')), 0, 'L');
        $pdf->Ln(6);

        foreach (is_array($block['lines'] ?? null) ? $block['lines'] : [] as $line) {
            $pdf->Ln(6);
            $pdf->MultiCell(0, 14, $this->latin((string) $line), 0, 'L');
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function size(array $block, int $default): int
    {
        $size = (int) ($block['size'] ?? $default);

        return max(8, min(18, $size));
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function align(array $block, string $default): string
    {
        return match ($block['align'] ?? '') {
            'center' => 'C',
            'right' => 'R',
            'left' => 'L',
            default => $default,
        };
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function style(array $block, bool $heading): string
    {
        $bold = (bool) ($block['bold'] ?? $heading);
        $italic = (bool) ($block['italic'] ?? false);

        return ($bold ? 'B' : '').($italic ? 'I' : '');
    }

    private function latin(string $value): string
    {
        $clean = str_replace(['₹', '—', '–', '’', '‘', '“', '”'], ['Rs. ', '-', '-', "'", "'", '"', '"'], $value);
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $clean);

        return is_string($converted) ? $converted : (preg_replace('/[^\x20-\x7E]/', '?', $clean) ?? '');
    }
}

class AppointmentStamp extends Fpdi
{
    public mixed $backgroundTemplate = null;

    public ?string $backgroundImage = null;

    /**
     * @var array{x: float, y: float, w: float, h: float}|null
     */
    public ?array $imageBox = null;

    public float $preparedBottom = 56;

    public float $headerReserve = 0;

    public float $footerReserve = 0;

    public function usePageSize(float $width, float $height): void
    {
        $this->DefPageSize = [$width, $height];
        $this->CurPageSize = [$width, $height];
        $this->w = $width;
        $this->h = $height;
        $this->wPt = $width * $this->k;
        $this->hPt = $height * $this->k;
    }

    public function AddPage($orientation = '', $size = '', $rotation = 0)
    {
        if ($this->page > 0) {
            if ($orientation === '') {
                $orientation = $this->CurOrientation;
            }

            if ($size === '') {
                $size = $this->CurPageSize;
            }
        }

        parent::AddPage($orientation, $size, $rotation);
    }

    public function contentLeft(): float
    {
        return $this->lMargin;
    }

    public function contentRight(): float
    {
        return $this->rMargin;
    }

    public function Header(): void
    {
        if ($this->headerReserve <= 0 && $this->footerReserve <= 0) {
            $this->paintBackground();

            return;
        }

        if ($this->headerReserve > 0) {
            $this->withClip(0, 0, $this->GetPageWidth(), $this->headerReserve, fn () => $this->paintBackground());
        }

        if ($this->footerReserve > 0) {
            $top = $this->GetPageHeight() - $this->footerReserve;
            $this->withClip(0, $top, $this->GetPageWidth(), $this->footerReserve, fn () => $this->paintBackground());
        }
    }

    private function paintBackground(): void
    {
        if ($this->backgroundTemplate !== null) {
            $this->useTemplate($this->backgroundTemplate, 0, 0, $this->GetPageWidth(), $this->GetPageHeight());
        }

        if ($this->backgroundImage !== null && $this->imageBox !== null) {
            $this->Image(
                $this->backgroundImage,
                $this->imageBox['x'],
                $this->imageBox['y'],
                $this->imageBox['w'],
                $this->imageBox['h'],
            );
        }
    }

    private function withClip(float $x, float $y, float $width, float $height, \Closure $draw): void
    {
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $scale = $this->k;
        $this->_out(sprintf('q %.2F %.2F %.2F %.2F re W n', $x * $scale, ($this->h - ($y + $height)) * $scale, $width * $scale, $height * $scale));
        $draw();
        $this->_out('Q');
    }
}
