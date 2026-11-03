<?php

namespace App\Services\Hrms;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class OfferLetterMarkup
{
    /**
     * @return array<string, mixed>
     */
    public static function parseStyle(string $style): array
    {
        $parsed = [];

        foreach (explode(';', $style) as $part) {
            [$property, $value] = array_pad(explode(':', $part, 2), 2, '');
            $property = strtolower(trim($property));
            $value = trim($value);

            if ($property === '' || $value === '') {
                continue;
            }

            match ($property) {
                'font-family' => $parsed['family'] = self::fontFamily($value),
                'font-size' => $parsed['size'] = self::fontSize($value),
                'color' => $parsed['color'] = self::color($value),
                'background-color' => $parsed['highlight'] = self::color($value),
                'text-align' => $parsed['align'] = self::align($value),
                'line-height' => $parsed['lineHeight'] = self::lineHeight($value),
                'margin-top' => $parsed['before'] = self::spacing($value),
                'margin-bottom' => $parsed['after'] = self::spacing($value),
                'text-decoration' => $parsed['underline'] = str_contains(strtolower($value), 'underline'),
                default => null,
            };
        }

        return array_filter($parsed, fn (mixed $value): bool => $value !== null);
    }

    public static function looksLikeHtml(string $body): bool
    {
        return preg_match('/<\s*(p|div|h1|h2|h3|ul|ol|table|br|span|strong|em|mark)\b/i', $body) === 1;
    }

    public static function sanitize(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        $clean = '';

        foreach ($body->childNodes as $child) {
            $clean .= self::emit($child);
        }

        return trim($clean);
    }

    private static function emit(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta'], true)) {
            return '';
        }

        $inner = '';

        foreach ($node->childNodes as $child) {
            $inner .= self::emit($child);
        }

        if ($tag === 'br') {
            return '<br>';
        }

        if ($tag === 'div' && $node->hasAttribute('data-page-break')) {
            return '<div data-page-break="true"></div>';
        }

        if ($tag === 'div' && $node->getAttribute('data-signature') === 'hr') {
            return '<div data-signature="hr"></div>';
        }

        $allowed = ['p', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'strong', 'b', 'em', 'i', 'u', 'span', 'mark', 'a'];

        if (! in_array($tag, $allowed, true)) {
            return $inner;
        }

        $attributes = self::attributes($node, $tag);

        if ($tag === 'a' && ! str_contains($attributes, 'href=')) {
            return $inner;
        }

        return '<'.$tag.$attributes.'>'.$inner.'</'.$tag.'>';
    }

    private static function attributes(DOMElement $node, string $tag): string
    {
        $attributes = '';
        $style = self::style($node->getAttribute('style'));

        if ($style !== '') {
            $attributes .= ' style="'.htmlspecialchars($style, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"';
        }

        if ($tag === 'a') {
            $href = self::href($node->getAttribute('href'));

            if ($href !== null) {
                $attributes .= ' href="'.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"';
            }
        }

        if ($tag === 'ol') {
            $type = strtolower($node->getAttribute('type'));

            if (in_array($type, ['1', 'a', 'i'], true)) {
                $attributes .= ' type="'.$type.'"';
            }
        }

        if (in_array($tag, ['td', 'th'], true)) {
            $span = (int) $node->getAttribute('colspan');

            if ($span > 1 && $span <= 8) {
                $attributes .= ' colspan="'.$span.'"';
            }
        }

        return $attributes;
    }

    private static function style(string $style): string
    {
        $clean = [];

        foreach (self::parseStyle($style) as $property => $value) {
            $css = match ($property) {
                'family' => 'font-family: '.$value,
                'size' => 'font-size: '.$value.'pt',
                'color' => 'color: '.self::hex($value),
                'highlight' => 'background-color: '.self::hex($value),
                'align' => 'text-align: '.$value,
                'lineHeight' => 'line-height: '.$value,
                'before' => 'margin-top: '.$value.'pt',
                'after' => 'margin-bottom: '.$value.'pt',
                'underline' => $value === true ? 'text-decoration: underline' : null,
                default => null,
            };

            if (is_string($css)) {
                $clean[] = $css;
            }
        }

        return implode('; ', $clean);
    }

    private static function href(string $href): ?string
    {
        $href = trim($href);

        if (preg_match('/^(https?:\/\/|mailto:)[^\s<>"\']+$/i', $href) !== 1) {
            return null;
        }

        if (preg_match('/^(javascript|data|vbscript):/i', $href) === 1) {
            return null;
        }

        return $href;
    }

    private static function fontFamily(string $value): ?string
    {
        $name = trim(str_replace(['"', "'"], '', explode(',', $value)[0]));
        $allowed = [
            'Times New Roman' => 'Times New Roman',
            'Times' => 'Times New Roman',
            'Georgia' => 'Georgia',
            'Arial' => 'Arial',
            'Helvetica' => 'Arial',
            'Calibri' => 'Calibri',
            'Courier New' => 'Courier New',
            'Courier' => 'Courier New',
        ];

        return $allowed[$name] ?? null;
    }

    private static function fontSize(string $value): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(px|pt)?$/', strtolower($value), $match) !== 1) {
            return null;
        }

        $size = (float) $match[1];

        if (($match[2] ?? 'pt') === 'px') {
            $size *= 0.75;
        }

        if ($size < 8 || $size > 36) {
            return null;
        }

        return round($size, 1);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function color(string $value): ?array
    {
        $value = strtolower(trim($value));

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $match) === 1) {
            $hex = $match[1];

            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        }

        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $value, $match) === 1) {
            $channels = [(int) $match[1], (int) $match[2], (int) $match[3]];

            foreach ($channels as $channel) {
                if ($channel > 255) {
                    return null;
                }
            }

            return $channels;
        }

        return null;
    }

    private static function align(string $value): ?string
    {
        $value = strtolower($value);

        return in_array($value, ['left', 'center', 'right', 'justify'], true) ? $value : null;
    }

    private static function lineHeight(string $value): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(px|pt)?$/', strtolower($value), $match) !== 1) {
            return null;
        }

        $height = (float) $match[1];

        if (($match[2] ?? '') !== '') {
            return null;
        }

        if ($height < 1 || $height > 3) {
            return null;
        }

        return round($height, 2);
    }

    private static function spacing(string $value): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(px|pt)$/', strtolower($value), $match) !== 1) {
            return null;
        }

        $space = (float) $match[1];

        if ($match[2] === 'px') {
            $space *= 0.75;
        }

        if ($space < 0 || $space > 48) {
            return null;
        }

        return round($space, 1);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private static function hex(array $color): string
    {
        return sprintf('#%02x%02x%02x', $color[0], $color[1], $color[2]);
    }
}
