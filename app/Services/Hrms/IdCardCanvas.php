<?php

namespace App\Services\Hrms;

/**
 * CR80 canvas with cubic Bézier fills. Coordinates are millimetres from the top-left.
 */
class IdCardCanvas extends \FPDF
{
    private const KAPPA = 0.5522847498;

    public function fillCornerSweep(float $spanX, float $spanY, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out(sprintf(
            '0 %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h f',
            $this->py(0.0),
            $this->px($spanX),
            $this->py(0.0),
            $this->px($spanX * 0.32),
            $this->py(0.0),
            $this->px(0.0),
            $this->py($spanY * 0.34),
            $this->px(0.0),
            $this->py($spanY)
        ));
    }

    /**
     * Filled corner bounded by one cubic from the top edge to the left edge.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillCornerCurve(float $topX, float $leftY, float $cx1, float $cy1, float $cx2, float $cy2, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out(sprintf(
            '0 %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h f',
            $this->py(0.0),
            $this->px($topX),
            $this->py(0.0),
            $this->px($cx1),
            $this->py($cy1),
            $this->px($cx2),
            $this->py($cy2),
            $this->px(0.0),
            $this->py($leftY)
        ));
    }

    /**
     * Filled corner bounded by one or more cubics from the top edge to the left edge.
     *
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>  $segments
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillCornerSegments(array $segments, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $parts = [];
        foreach ($segments as $curve) {
            $parts[] = sprintf(
                '%.2F %.2F %.2F %.2F %.2F %.2F c',
                $this->px($curve[2]),
                $this->py($curve[3]),
                $this->px($curve[4]),
                $this->py($curve[5]),
                $this->px($curve[6]),
                $this->py($curve[7])
            );
        }
        $this->_out(sprintf(
            '0 %.2F m %.2F %.2F l %s h f',
            $this->py(0.0),
            $this->px($segments[0][0]),
            $this->py($segments[0][1]),
            implode(' ', $parts)
        ));
    }

    public function endClip(): void
    {
        $this->_out('Q');
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $curve
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillAbove(array $curve, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out($this->abovePath($curve).' f');
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $curve
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillBelow(array $curve, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out($this->belowPath($curve).' f');
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $top
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $bottom
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillRibbon(array $top, array $bottom, array $rgb): void
    {
        $this->fillRibbonSegments([$top], [$bottom], $rgb);
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>  $top
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>  $bottom
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillRibbonSegments(array $top, array $bottom, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out($this->forwardSegments($top).' '.$this->reverseSegments($bottom).' h f');
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>  $segments
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillAboveSegments(array $segments, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out(sprintf(
            '0 %.2F m %.2F %.2F l %s h f',
            $this->py(0),
            $this->px($this->w),
            $this->py(0),
            $this->reverseSegments($segments)
        ));
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>  $segments
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillBelowSegments(array $segments, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out(sprintf(
            '0 %.2F m %.2F %.2F l %s h f',
            $this->py($this->h),
            $this->px($this->w),
            $this->py($this->h),
            $this->reverseSegments($segments)
        ));
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function fillRoundRect(float $x, float $y, float $w, float $h, float $r, array $rgb): void
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->_out($this->roundRectPath($x, $y, $w, $h, $r).' f');
    }

    public function clipRoundRect(float $x, float $y, float $w, float $h, float $r): void
    {
        $this->_out('q '.$this->roundRectPath($x, $y, $w, $h, $r).' W n');
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $curve
     */
    public function clipAbove(array $curve): void
    {
        $this->_out('q '.$this->abovePath($curve).' W n');
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $curve
     */
    private function abovePath(array $curve): string
    {
        return sprintf(
            '0 %.2F m %.2F %.2F l %s h',
            $this->py(0),
            $this->px($this->w),
            $this->py(0),
            $this->reverse($curve)
        );
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $curve
     */
    private function belowPath(array $curve): string
    {
        return sprintf(
            '0 %.2F m %.2F %.2F l %s h',
            $this->py($this->h),
            $this->px($this->w),
            $this->py($this->h),
            $this->reverse($curve)
        );
    }

    /**
     * Left-to-right cubic: x0 y0 cx1 cy1 cx2 cy2 x1 y1.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $curve
     */
    private function forward(array $curve): string
    {
        return sprintf(
            '%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
            $this->px($curve[0]),
            $this->py($curve[1]),
            $this->px($curve[2]),
            $this->py($curve[3]),
            $this->px($curve[4]),
            $this->py($curve[5]),
            $this->px($curve[6]),
            $this->py($curve[7])
        );
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}  $curve
     */
    private function reverse(array $curve): string
    {
        return sprintf(
            '%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c',
            $this->px($curve[6]),
            $this->py($curve[7]),
            $this->px($curve[4]),
            $this->py($curve[5]),
            $this->px($curve[2]),
            $this->py($curve[3]),
            $this->px($curve[0]),
            $this->py($curve[1])
        );
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>  $segments
     */
    private function forwardSegments(array $segments): string
    {
        $parts = [];
        foreach ($segments as $index => $curve) {
            if ($index === 0) {
                $parts[] = $this->forward($curve);

                continue;
            }
            $parts[] = sprintf(
                '%.2F %.2F %.2F %.2F %.2F %.2F c',
                $this->px($curve[2]),
                $this->py($curve[3]),
                $this->px($curve[4]),
                $this->py($curve[5]),
                $this->px($curve[6]),
                $this->py($curve[7])
            );
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>  $segments
     */
    private function reverseSegments(array $segments): string
    {
        $parts = [];
        foreach (array_reverse($segments) as $curve) {
            $parts[] = $this->reverse($curve);
        }

        return implode(' ', $parts);
    }

    private function roundRectPath(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = min($r, $w / 2, $h / 2);
        $k = $r * self::KAPPA;
        $right = $x + $w;
        $bottom = $y + $h;

        return sprintf(
            '%.2F %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h',
            $this->px($x + $r), $this->py($y),
            $this->px($right - $r), $this->py($y),
            $this->px($right - $r + $k), $this->py($y), $this->px($right), $this->py($y + $r - $k), $this->px($right), $this->py($y + $r),
            $this->px($right), $this->py($bottom - $r),
            $this->px($right), $this->py($bottom - $r + $k), $this->px($right - $r + $k), $this->py($bottom), $this->px($right - $r), $this->py($bottom),
            $this->px($x + $r), $this->py($bottom),
            $this->px($x + $r - $k), $this->py($bottom), $this->px($x), $this->py($bottom - $r + $k), $this->px($x), $this->py($bottom - $r),
            $this->px($x), $this->py($y + $r),
            $this->px($x), $this->py($y + $r - $k), $this->px($x + $r - $k), $this->py($y), $this->px($x + $r), $this->py($y)
        );
    }

    private function px(float $x): float
    {
        return $x * $this->k;
    }

    private function py(float $y): float
    {
        return ($this->h - $y) * $this->k;
    }
}
