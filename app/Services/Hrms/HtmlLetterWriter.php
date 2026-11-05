<?php

namespace App\Services\Hrms;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class HtmlLetterWriter
{
    /**
     * @param  array<string, mixed>  $signature
     * @param  array<string, mixed>  $base
     */
    public function __construct(
        private readonly AppointmentStamp $pdf,
        private readonly array $signature,
        private readonly Closure $placeSignature,
        private array $base = [],
    ) {
        $this->base = array_merge([
            'family' => 'Helvetica',
            'size' => 11.0,
            'bold' => false,
            'italic' => false,
            'underline' => false,
            'color' => [15, 23, 42],
            'highlight' => null,
            'link' => null,
            'align' => 'left',
            'lineHeight' => 1.3,
            'before' => 0.0,
            'after' => 6.0,
        ], $base);
    }

    public function write(string $html): void
    {
        $this->pdf->SetAutoPageBreak(false, $this->pdf->preparedBottom);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return;
        }

        foreach ($body->childNodes as $child) {
            $this->writeBlock($child);
        }
    }

    private function writeBlock(DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            if (trim($node->textContent) === '') {
                return;
            }

            $this->drawRuns([['text' => $node->textContent, 'style' => $this->base]], 0, $this->contentWidth(), $this->base);

            return;
        }

        if (! $node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'div' && $node->hasAttribute('data-page-break')) {
            $this->pdf->AddPage();

            return;
        }

        if ($tag === 'div' && $node->getAttribute('data-signature') === 'hr') {
            if (($this->signature['enabled'] ?? false) && is_string($this->signature['path'] ?? null) && is_file($this->signature['path'])) {
                ($this->placeSignature)();
            }

            return;
        }

        if ($tag === 'div') {
            foreach ($node->childNodes as $child) {
                $this->writeBlock($child);
            }

            return;
        }

        if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
            $this->reserveHeading($node);
            $this->writeParagraph($node);

            return;
        }

        if (in_array($tag, ['p', 'a', 'span', 'strong', 'b', 'em', 'i', 'u', 'mark'], true)) {
            $this->writeParagraph($node);

            return;
        }

        if ($tag === 'ul' || $tag === 'ol') {
            $this->writeList($node, 0);

            return;
        }

        if ($tag === 'table') {
            $this->writeTable($node);
        }
    }

    private function writeParagraph(DOMElement $node): void
    {
        $style = $this->inlineStyle($node, $this->blockStyle($node));
        $runs = [];
        $this->inlineRuns($node, $style, $runs);
        $this->drawRuns($runs, 0, $this->contentWidth(), $style);
    }

    private function writeList(DOMElement $list, float $indent): void
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $roman = strtolower($list->getAttribute('type')) === 'i';
        $number = 1;

        foreach ($list->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $marker = $ordered ? ($roman ? $this->roman($number).'. ' : $number.'. ') : "\x95 ";
            $number++;
            $style = $this->blockStyle($child);
            $runs = [['text' => $marker, 'style' => $style, 'encoded' => ! $ordered]];
            $this->inlineRuns($child, $style, $runs, true);
            $this->drawRuns($runs, $indent, $this->contentWidth() - $indent, $style);

            foreach ($child->childNodes as $nested) {
                if ($nested instanceof DOMElement && in_array(strtolower($nested->tagName), ['ul', 'ol'], true)) {
                    $this->writeList($nested, $indent + 16);
                }
            }
        }
    }

    private function writeTable(DOMElement $table): void
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = $cell;
                }
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return;
        }

        $columns = max(array_map(fn (array $cells): int => count($cells), $rows));
        $width = $this->contentWidth() / max(1, $columns);
        $this->pdf->SetDrawColor(148, 163, 184);

        foreach ($rows as $cells) {
            $prepared = [];
            $rowHeight = 18.0;

            foreach ($cells as $cell) {
                $style = $this->blockStyle($cell);
                $style['bold'] = $style['bold'] || strtolower($cell->tagName) === 'th';
                $style['before'] = 0;
                $style['after'] = 0;
                $runs = [];
                $this->inlineRuns($cell, $style, $runs, true);
                $lines = $this->wrap($runs, $width - 8);
                $leading = $this->leading($style, $lines);
                $height = max($leading, count($lines) * $leading) + 8;
                $rowHeight = max($rowHeight, $height);
                $prepared[] = ['lines' => $lines, 'style' => $style, 'leading' => $leading];
            }

            $this->ensure($rowHeight);
            $x = $this->pdf->contentLeft();
            $y = $this->pdf->GetY();

            foreach ($prepared as $cell) {
                $this->pdf->Rect($x, $y, $width, $rowHeight);
                $this->paintLines($cell['lines'], $x + 4, $y + 4, $width - 8, $cell['style'], $cell['leading']);
                $x += $width;
            }

            $this->pdf->SetXY($this->pdf->contentLeft(), $y + $rowHeight);
        }

        $this->pdf->Ln(6);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $style
     */
    private function drawRuns(array $runs, float $indent, float $width, array $style): void
    {
        $this->gap((float) $style['before']);
        $lines = $this->wrap($runs, $width);
        $leading = $this->leading($style, $lines);

        if ($lines === []) {
            $this->ensure($leading);
            $this->pdf->SetY($this->pdf->GetY() + $leading);
        } else {
            $this->paintLines($lines, $this->pdf->contentLeft() + $indent, null, $width, $style, $leading);
        }

        $this->gap((float) $style['after']);
    }

    /**
     * @param  list<list<array<string, mixed>>>  $lines
     * @param  array<string, mixed>  $style
     */
    private function paintLines(array $lines, float $x, ?float $y, float $width, array $style, float $leading): void
    {
        $last = array_key_last($lines);
        $top = $y ?? $this->pdf->GetY();

        foreach ($lines as $index => $line) {
            if ($y === null) {
                $this->ensure($leading);
                $top = $this->pdf->GetY();
            }

            $lineWidth = array_sum(array_map(fn (array $word): float => (float) $word['width'], $line));
            $align = (string) $style['align'];
            $start = $x;

            if ($align === 'center') {
                $start += max(0, ($width - $lineWidth) / 2);
            } elseif ($align === 'right') {
                $start += max(0, $width - $lineWidth);
            } elseif ($align === 'justify' && $index !== $last) {
                $gaps = count(array_filter($line, fn (array $word): bool => ($word['space'] ?? false) === true));

                if ($gaps > 0) {
                    $extra = max(0, $width - $lineWidth) / $gaps;

                    foreach ($line as &$word) {
                        if (($word['space'] ?? false) === true) {
                            $word['width'] += $extra;
                        }
                    }
                    unset($word);
                }
            }

            $cursor = $start;

            foreach ($line as $word) {
                $this->paint($word, $cursor, $top, $leading);
                $cursor += (float) $word['width'];
            }

            $top += $leading;

            if ($y === null) {
                $this->pdf->SetXY($this->pdf->contentLeft(), $top);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $word
     */
    private function paint(array $word, float $x, float $y, float $leading): void
    {
        $this->applyFont($word);

        if (is_array($word['highlight'] ?? null)) {
            $this->pdf->SetFillColor(...$word['highlight']);
            $this->pdf->Rect($x, $y, (float) $word['width'], $leading, 'F');
            $this->applyFont($word);
        }

        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell((float) $word['width'], $leading, (string) $word['text'], 0, 0, 'L');

        if (is_string($word['link'] ?? null) && $word['link'] !== '') {
            $this->pdf->Link($x, $y, (float) $word['width'], $leading, $word['link']);
        }
    }

    /**
     * @param  array<string, mixed>  $style
     * @param  list<array<string, mixed>>  $runs
     */
    private function inlineRuns(DOMNode $node, array $style, array &$runs, bool $flattenBlocks = false): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if ($child->textContent !== '') {
                    $runs[] = ['text' => $child->textContent, 'style' => $style];
                }

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if ($tag === 'br') {
                $next = $child->nextSibling;
                $alreadyBroken = $next instanceof DOMText && str_starts_with($next->textContent, "\n");

                if (! $alreadyBroken) {
                    $runs[] = ['text' => "\n", 'style' => $style];
                }

                continue;
            }

            if (in_array($tag, ['ul', 'ol', 'table'], true) || ($tag === 'div' && ($child->hasAttribute('data-page-break') || $child->hasAttribute('data-signature')))) {
                continue;
            }

            $block = in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'blockquote', 'li'], true);

            if ($block && ! $flattenBlocks) {
                $this->pushLineBreak($runs, $style);
            }

            if ($flattenBlocks && in_array($tag, ['p', 'h1', 'h2', 'h3'], true) && $runs !== []) {
                $previous = (string) ($runs[array_key_last($runs)]['text'] ?? '');

                if ($previous === '' || ! ctype_space(substr($previous, -1))) {
                    $runs[] = ['text' => ' ', 'style' => $style];
                }
            }

            $this->inlineRuns($child, $this->inlineStyle($child, $style), $runs, $flattenBlocks);

            if ($block && ! $flattenBlocks) {
                $this->pushLineBreak($runs, $style);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $style
     */
    private function pushLineBreak(array &$runs, array $style): void
    {
        $previous = $runs === [] ? '' : (string) ($runs[array_key_last($runs)]['text'] ?? '');

        if ($previous === '' || str_ends_with($previous, "\n")) {
            return;
        }

        $runs[] = ['text' => "\n", 'style' => $style];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<list<array<string, mixed>>>
     */
    private function wrap(array $runs, float $width): array
    {
        $words = [];

        foreach ($runs as $run) {
            $style = is_array($run['style'] ?? null) ? $run['style'] : $this->base;
            $parts = preg_split("/(\n|\s+)/", (string) ($run['text'] ?? ''), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                if ($part === "\n") {
                    $words[] = ['break' => true];

                    continue;
                }

                $encoded = ($run['encoded'] ?? false) === true ? $part : $this->encode($part);
                $this->applyFont($style + ['text' => $encoded]);
                $words[] = [
                    'break' => false,
                    'space' => trim($part) === '',
                    'text' => $encoded,
                    'width' => $this->pdf->GetStringWidth($encoded),
                    'family' => $style['family'],
                    'size' => $style['size'],
                    'bold' => $style['bold'],
                    'italic' => $style['italic'],
                    'underline' => $style['underline'],
                    'color' => $style['color'],
                    'highlight' => $style['highlight'],
                    'link' => $style['link'],
                ];
            }
        }

        $lines = [];
        $current = [];
        $used = 0.0;

        foreach ($words as $word) {
            if (($word['break'] ?? false) === true) {
                $lines[] = $current;
                $current = [];
                $used = 0;

                continue;
            }

            $wordWidth = (float) $word['width'];

            if ($current !== [] && $used + $wordWidth > $width + 0.2) {
                $lines[] = $current;
                $current = [];
                $used = 0;

                if (($word['space'] ?? false) === true) {
                    continue;
                }
            }

            if (($word['space'] ?? false) === true && $current === []) {
                continue;
            }

            $current[] = $word;
            $used += $wordWidth;
        }

        if ($current !== []) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $style
     * @param  list<list<array<string, mixed>>>  $lines
     */
    private function leading(array $style, array $lines): float
    {
        $size = (float) $style['size'];

        foreach ($lines as $line) {
            foreach ($line as $word) {
                $size = max($size, (float) ($word['size'] ?? $size));
            }
        }

        return max(10, $size * (float) $style['lineHeight']);
    }

    /**
     * @param  array<string, mixed>  $parent
     * @return array<string, mixed>
     */
    private function blockStyle(DOMElement $node): array
    {
        $style = $this->base;
        $tag = strtolower($node->tagName);

        if ($tag === 'h1') {
            $style['size'] = 16;
            $style['bold'] = true;
            $style['align'] = 'center';
            $style['family'] = 'Times';
            $style['after'] = 10;
        } elseif ($tag === 'h2') {
            $style['size'] = 12;
            $style['bold'] = true;
            $style['before'] = 8;
            $style['after'] = 3;
        } elseif ($tag === 'h3') {
            $style['size'] = 11;
            $style['bold'] = true;
            $style['before'] = 6;
            $style['after'] = 2;
        } elseif ($tag === 'li') {
            $style['before'] = 1;
            $style['after'] = 1;
        }

        return $this->applyParsed($style, $node);
    }

    /**
     * @param  array<string, mixed>  $parent
     * @return array<string, mixed>
     */
    private function inlineStyle(DOMElement $node, array $parent): array
    {
        $style = $parent;
        $tag = strtolower($node->tagName);

        if ($tag === 'strong' || $tag === 'b' || $tag === 'th') {
            $style['bold'] = true;
        }

        if ($tag === 'em' || $tag === 'i') {
            $style['italic'] = true;
        }

        if ($tag === 'u') {
            $style['underline'] = true;
        }

        if ($tag === 'mark' && $style['highlight'] === null) {
            $style['highlight'] = [254, 240, 138];
        }

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));

            if (preg_match('/^(https?:\/\/|mailto:)[^\s<>"\']+$/i', $href) === 1) {
                $style['link'] = $href;
                $style['underline'] = true;
                $style['color'] = [29, 78, 216];
            }
        }

        return $this->applyParsed($style, $node);
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private function applyParsed(array $style, DOMElement $node): array
    {
        foreach (OfferLetterMarkup::parseStyle($node->getAttribute('style')) as $property => $value) {
            if ($property === 'family') {
                $style['family'] = $this->pdfFamily((string) $value);
            } elseif ($property === 'underline') {
                $style['underline'] = $value === true;
            } elseif ($value !== null) {
                $style[$property] = $value;
            }
        }

        return $style;
    }

    /**
     * @param  array<string, mixed>  $style
     */
    private function applyFont(array $style): void
    {
        $font = ($style['bold'] ? 'B' : '').($style['italic'] ? 'I' : '').($style['underline'] ? 'U' : '');
        $this->pdf->SetFont($this->pdfFamily((string) ($style['family'] ?? 'Helvetica')), $font, (float) ($style['size'] ?? 11));
        $color = is_array($style['color'] ?? null) ? $style['color'] : [15, 23, 42];
        $this->pdf->SetTextColor((int) $color[0], (int) $color[1], (int) $color[2]);
    }

    private function pdfFamily(string $family): string
    {
        $name = strtolower($family);

        if (str_contains($name, 'courier') || str_contains($name, 'mono')) {
            return 'Courier';
        }

        if (str_contains($name, 'times') || str_contains($name, 'georgia') || $name === 'serif') {
            return 'Times';
        }

        return 'Helvetica';
    }

    private function gap(float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->ensure($amount);
        $this->pdf->SetY($this->pdf->GetY() + $amount);
    }

    private function reserveHeading(DOMElement $node): void
    {
        $style = $this->blockStyle($node);
        $runs = [];
        $this->inlineRuns($node, $style, $runs);
        $lines = $this->wrap($runs, $this->contentWidth());
        $leading = $this->leading($style, $lines === [] ? [[]] : $lines);
        $height = (float) $style['before'] + max(1, count($lines)) * $leading + (float) $style['after'] + 28;
        $this->ensure($height);
    }

    private function ensure(float $height): void
    {
        if ($this->pdf->GetY() + $height > $this->pdf->GetPageHeight() - $this->pdf->preparedBottom) {
            $this->pdf->AddPage();
        }
    }

    private function contentWidth(): float
    {
        return $this->pdf->GetPageWidth() - $this->pdf->contentLeft() - $this->pdf->contentRight();
    }

    private function encode(string $value): string
    {
        $clean = str_replace(['₹', '—', '–', '’', '‘', '“', '”'], ['Rs. ', '-', '-', "'", "'", '"', '"'], $value);
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $clean);

        return is_string($converted) && $converted !== '' ? $converted : (preg_replace('/[^\x20-\x7E]/', '?', $clean) ?? '');
    }

    private function roman(int $number): string
    {
        $map = ['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1];
        $roman = '';

        foreach ($map as $glyph => $value) {
            while ($number >= $value) {
                $roman .= $glyph;
                $number -= $value;
            }
        }

        return $roman;
    }
}
