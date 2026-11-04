<?php

namespace App\Services\Hrms;

use Illuminate\Validation\ValidationException;

class UsaOfferPdf
{
    private const PAGE_WIDTH = 612.0;

    private const PAGE_HEIGHT = 792.0;

    private const SCALE = 2.0;

    private const MARGIN_X = 54.0;

    private const CONTENT_WIDTH = 504.0;

    private const BODY_TOP = 108.0;

    private const BODY_BOTTOM = 742.0;

    private const BODY = 11.0;

    private const LEADING = 14.0;

    private const HEADING = 13.0;

    private ?string $letterhead = null;

    private ?string $signature = null;

    /** @var array<string, mixed> */
    private array $profile = [];

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $profile
     */
    public function render(array $offer, array $profile, ?string $letterhead = null, ?string $signature = null): string
    {
        $this->profile = $profile;
        $this->requireIdentity();
        $this->letterhead = $letterhead !== null && is_file($letterhead) ? $letterhead : null;
        $this->signature = $signature !== null && is_file($signature) ? $signature : null;
        $pages = $this->compose($offer);
        $files = [];

        try {
            foreach ($pages as $page) {
                $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'usa-offer-'.bin2hex(random_bytes(8)).'.jpg';
                imagejpeg($page, $file, 90);
                imagedestroy($page);
                unset($page);
                $files[] = $file;
            }

            return $this->pdf($files);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function pages(string $pdf): int
    {
        preg_match_all('/\/Type \/Page(?!s)/', $pdf, $matches);

        return count($matches[0]);
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return \Generator<int, \GdImage>
     */
    private function compose(array $offer): \Generator
    {
        $image = $this->chrome();
        $y = $this->px(self::BODY_TOP);
        $this->date($image, $this->text($offer['issue_date'] ?? ''));
        $y = $this->sender($image, $y);
        $y += $this->px(8);
        $y = $this->recipient($image, $y, $offer);
        $y += $this->px(10);
        $name = $this->text($offer['candidate_name'] ?? '');

        foreach ($this->wrappedRuns([
            ['Dear ', false, [0, 0, 0], false],
            [$name, true, [0, 0, 0], false],
            [',', false, [0, 0, 0], false],
        ], self::BODY, $this->px(self::CONTENT_WIDTH)) as $line) {
            $y = $this->paint($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, self::LEADING);
        }

        $y += $this->px(4);

        foreach ($this->wrappedRuns($this->offerRuns($offer), self::BODY, $this->px(self::CONTENT_WIDTH)) as $line) {
            if ($y + $this->px(self::LEADING) > $this->px(self::BODY_BOTTOM)) {
                yield $image;
                $image = $this->chrome();
                $y = $this->px(self::BODY_TOP);
            }

            $y = $this->paint($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, self::LEADING);
        }

        $y += $this->px(8);
        $responsibilities = is_array($offer['responsibilities'] ?? null) ? $offer['responsibilities'] : [];
        $items = [];

        foreach ($responsibilities as $item) {
            $line = $this->text($item);

            if ($line !== '') {
                $items[] = $line;
            }
        }

        if ($items !== []) {
            if ($y + $this->px(self::HEADING + self::LEADING) > $this->px(self::BODY_BOTTOM)) {
                yield $image;
                $image = $this->chrome();
                $y = $this->px(self::BODY_TOP);
            }

            $this->draw($image, 'Responsibilities', $this->px(self::MARGIN_X), $y, self::HEADING, true, [0, 0, 0]);
            $y += $this->px(self::LEADING + 2);

            foreach ($items as $item) {
                $height = $this->bulletHeight($item);

                if ($y + $height > $this->px(self::BODY_BOTTOM)) {
                    yield $image;
                    $image = $this->chrome();
                    $y = $this->px(self::BODY_TOP);
                }

                $y = $this->bullet($image, $item, $y);
            }
        }

        $y += $this->px(8);
        $report = $this->wrappedRuns($this->reportRuns(), self::BODY, $this->px(self::CONTENT_WIDTH));
        $reportHeight = count($report) * $this->px(self::LEADING);

        if ($y + $reportHeight > $this->px(self::BODY_BOTTOM)) {
            yield $image;
            $image = $this->chrome();
            $y = $this->px(self::BODY_TOP);
        }

        foreach ($report as $line) {
            $y = $this->paint($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, self::LEADING);
        }

        $y += $this->px(8);
        $signatoryHeight = $this->px(14 + 36) + (5 * $this->px(self::LEADING));

        if ($y + $signatoryHeight > $this->px(self::BODY_BOTTOM)) {
            yield $image;
            $image = $this->chrome();
            $y = $this->px(self::BODY_TOP);
        }

        $y = $this->signatory($image, $y);
        $acceptance = $this->wrappedRuns([['I have read this offer and accept the terms of appointment described herein.', false, [0, 0, 0], false]], self::BODY, $this->px(self::CONTENT_WIDTH));
        $acceptanceHeight = (count($acceptance) * $this->px(self::LEADING)) + $this->px(12 + 24 + 6);

        if ($y + $acceptanceHeight > $this->px(self::BODY_BOTTOM)) {
            yield $image;
            $image = $this->chrome();
            $y = $this->px(self::BODY_TOP);
        }

        $this->acceptance($image, $y, $acceptance);

        yield $image;
    }

    /**
     * @param  list<string>  $files
     */
    private function pdf(array $files): string
    {
        require_once base_path('vendor/setasign/fpdf/fpdf.php');
        $pdf = new \FPDF('P', 'pt', 'Letter');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCompression(true);

        foreach ($files as $file) {
            $pdf->AddPage();
            $pdf->Image($file, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT);
        }

        return $pdf->Output('S');
    }

    private function sender(\GdImage $image, int $y): int
    {
        $this->draw($image, 'From', $this->px(self::MARGIN_X), $y, self::BODY, true, [0, 0, 0]);
        $y += $this->px(self::LEADING);

        foreach ($this->wrapPlain($this->field('company_name'), self::BODY, true, $this->px(self::CONTENT_WIDTH)) as $line) {
            $this->draw($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, true, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        foreach ($this->postalLines() as $line) {
            $this->draw($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, false, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        if ($this->field('e_verify') !== '') {
            $label = 'E-Verify No: ';
            $this->draw($image, $label, $this->px(self::MARGIN_X), $y, self::BODY, false, [0, 0, 0]);
            $this->draw($image, $this->field('e_verify'), $this->px(self::MARGIN_X) + $this->width($label, self::BODY, false), $y, self::BODY, true, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        $label = 'Federal ID (EIN): ';
        $this->draw($image, $label, $this->px(self::MARGIN_X), $y, self::BODY, false, [0, 0, 0]);
        $this->draw($image, $this->field('federal_id'), $this->px(self::MARGIN_X) + $this->width($label, self::BODY, false), $y, self::BODY, true, [0, 0, 0]);

        return $y + $this->px(self::LEADING);
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function recipient(\GdImage $image, int $y, array $offer): int
    {
        $this->draw($image, 'To', $this->px(self::MARGIN_X), $y, self::BODY, true, [0, 0, 0]);
        $y += $this->px(self::LEADING);
        $name = $this->text($offer['candidate_name'] ?? '');

        foreach ($this->wrapPlain($name === '' ? '' : mb_strtoupper($name), self::BODY, true, $this->px(self::CONTENT_WIDTH)) as $line) {
            $this->draw($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, true, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        foreach ([$this->text($offer['candidate_address'] ?? ''), $this->text($offer['candidate_email'] ?? ''), $this->text($offer['candidate_phone'] ?? '')] as $part) {
            foreach ($this->wrapPlain($part, self::BODY, false, $this->px(self::CONTENT_WIDTH)) as $line) {
                $this->draw($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, false, [0, 0, 0]);
                $y += $this->px(self::LEADING);
            }
        }

        return $y;
    }

    private function signatory(\GdImage $image, int $y): int
    {
        $x = $this->px(self::MARGIN_X);
        $this->draw($image, 'Sincerely,', $x, $y, self::BODY, false, [0, 0, 0]);
        $y += $this->px(14);
        $this->paintSignature($image, $x, $y);
        $y += $this->px(36);

        if ($this->field('hr_name') !== '') {
            $this->draw($image, $this->field('hr_name'), $x, $y, self::BODY, true, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        if ($this->field('hr_title') !== '') {
            $this->draw($image, $this->field('hr_title'), $x, $y, self::BODY, false, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        if ($this->field('hr_phone') !== '') {
            $label = 'Phone: ';
            $this->draw($image, $label, $x, $y, self::BODY, false, [0, 0, 0]);
            $this->draw($image, $this->field('hr_phone'), $x + $this->width($label, self::BODY, false), $y, self::BODY, true, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        foreach ($this->wrapPlain(mb_strtoupper($this->field('company_name')), self::BODY, true, $this->px(self::CONTENT_WIDTH)) as $line) {
            $this->draw($image, $line, $x, $y, self::BODY, true, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        return $y + $this->px(10);
    }

    /**
     * @param  list<list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>>  $lines
     */
    private function acceptance(\GdImage $image, int $y, array $lines): void
    {
        foreach ($lines as $line) {
            $y = $this->paint($image, $line, $this->px(self::MARGIN_X), $y, self::BODY, self::LEADING);
        }

        $y += $this->px(12);
        $left = $this->px(self::MARGIN_X);
        $dateLabel = $this->px(390);
        $this->draw($image, 'Employee Signature', $left, $y, self::BODY, true, [0, 0, 0]);
        $this->draw($image, 'Date', $dateLabel, $y, self::BODY, true, [0, 0, 0]);
        $lineY = $y + $this->px(24);
        $ink = imagecolorallocate($image, 30, 30, 30);
        imageline($image, $left, $lineY, $this->px(300), $lineY, $ink);
        imageline($image, $dateLabel, $lineY, $this->px(530), $lineY, $ink);
    }

    /**
     * @return list<string>
     */
    private function postalLines(): array
    {
        $street = $this->field('address_line');
        $locality = $this->field('city_line');
        $country = $this->field('country_line');

        if (preg_match('/^(suite|ste\.?|unit|apt\.?|apartment)\s+[A-Za-z0-9-]+/i', $locality, $match) === 1) {
            $street = trim($street.($street !== '' ? ', ' : '').$match[0]);
            $locality = ltrim(trim(substr($locality, strlen($match[0]))), ", \t");
        }

        $lines = [];

        foreach ([$street, $locality, $country] as $part) {
            foreach ($this->wrapPlain($part, self::BODY, false, $this->px(self::CONTENT_WIDTH)) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function chrome(): \GdImage
    {
        $image = imagecreatetruecolor($this->px(self::PAGE_WIDTH), $this->px(self::PAGE_HEIGHT));
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), imagecolorallocate($image, 255, 255, 255));

        if ($this->letterhead !== null) {
            $this->paintLetterhead($image, $this->letterhead);

            return $image;
        }

        $contact = array_values(array_filter([
            $this->field('website'),
            $this->field('email'),
            $this->field('phone'),
        ], fn (string $line): bool => $line !== ''));
        $footer = array_values(array_filter([
            implode('   |   ', $contact),
            implode(', ', $this->postalLines()),
        ], fn (string $line): bool => $line !== ''));
        $y = $this->px(768);

        foreach ($footer as $line) {
            foreach ($this->wrapPlain($line, 8, false, $this->px(self::CONTENT_WIDTH)) as $wrapped) {
                if ($y > $this->px(784)) {
                    break 2;
                }

                $this->draw($image, $wrapped, $this->px(self::MARGIN_X), $y, 8, false, [40, 40, 40]);
                $y += $this->px(10);
            }
        }

        return $image;
    }

    private function date(\GdImage $image, string $iso): void
    {
        $time = strtotime($iso);
        $day = $time === false ? '' : date('j', $time);
        $month = $time === false ? $iso : date('F', $time);
        $year = $time === false ? '' : date('Y', $time);
        $suffix = $this->ordinal((int) $day);
        $lead = trim($month.' '.$day);
        $tail = ', '.$year;
        $width = $this->width($lead, self::BODY, true) + $this->width($suffix, 8, true) + $this->width($tail, self::BODY, true);
        $x = $this->px(self::MARGIN_X + self::CONTENT_WIDTH) - $width;
        $y = $this->px(92);
        $this->draw($image, $lead, $x, $y, self::BODY, true, [0, 0, 0]);
        $x += $this->width($lead, self::BODY, true);
        $this->draw($image, $suffix, $x, $y - $this->px(4), 8, true, [0, 0, 0]);
        $x += $this->width($suffix, 8, true);
        $this->draw($image, $tail, $x, $y, self::BODY, true, [0, 0, 0]);
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    private function offerRuns(array $offer): array
    {
        $amount = '$'.number_format((float) ($offer['pay_amount'] ?? 0), 2).' USD';
        $pay = ($offer['pay_type'] ?? 'hourly') === 'annual'
            ? 'at an annual salary of '
            : 'at an hourly rate of ';
        $start = $this->usDate($this->text($offer['start_date'] ?? ''));

        $runs = [
            ['This letter is to confirm that ', false, [0, 0, 0], false],
            [$this->field('company_name'), true, [0, 0, 0], false],
            [', with Federal Employer Identification Number (EIN) ', false, [0, 0, 0], false],
            [$this->field('federal_id'), true, [0, 0, 0], false],
            [', is delighted to offer you the position of ', false, [0, 0, 0], false],
            [$this->text($offer['designation_name'] ?? ''), true, [0, 0, 0], false],
            [' '.$pay, false, [0, 0, 0], false],
            [$amount, true, [0, 0, 0], false],
            ['. You will be working ', false, [0, 0, 0], false],
            [$this->text($offer['working_hours'] ?? ''), true, [0, 0, 0], false],
            [' in a ', false, [0, 0, 0], false],
            [$this->text($offer['work_arrangement'] ?? ''), true, [0, 0, 0], false],
            [', with a start date of ', false, [0, 0, 0], false],
            [$start, true, [0, 0, 0], false],
            ['. This employment will be associated with your employment authorization status under your academic program at ', false, [0, 0, 0], false],
            [$this->text($offer['university'] ?? ''), true, [0, 0, 0], false],
            ['. We look forward to your contributions and are excited about the skills and expertise you will bring to our team.', false, [0, 0, 0], false],
        ];
        $employment = $this->text($offer['employment_type'] ?? '');

        if ($employment !== '') {
            $runs[] = [' Employment type: ', false, [0, 0, 0], false];
            $runs[] = [$employment, true, [0, 0, 0], false];
            $runs[] = ['.', false, [0, 0, 0], false];
        }

        return $runs;
    }

    /**
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    private function reportRuns(): array
    {
        $who = trim($this->field('manager_name').($this->field('manager_title') !== '' ? ', '.$this->field('manager_title') : ''));
        $runs = [
            ['You will report your day-to-day activities directly to ', false, [0, 0, 0], false],
            [$who, true, [0, 0, 0], false],
            ['.', false, [0, 0, 0], false],
        ];
        $email = $this->field('manager_email');
        $phone = $this->field('manager_phone');

        if ($email !== '') {
            $runs[] = [' The manager can be reached by email at ', false, [0, 0, 0], false];
            $runs[] = [$email, true, [20, 78, 160], true];
        }

        if ($phone !== '') {
            $runs[] = [$email !== '' ? ' or by phone at ' : ' The manager can be reached by phone at ', false, [0, 0, 0], false];
            $runs[] = [$phone, true, [0, 0, 0], false];
        }

        if ($email !== '' || $phone !== '') {
            $runs[] = ['.', false, [0, 0, 0], false];
        }

        return $runs;
    }

    private function field(string $key): string
    {
        return trim((string) ($this->profile[$key] ?? ''));
    }

    private function requireIdentity(): void
    {
        if ($this->field('company_name') === '' || $this->field('address_line') === '' || $this->field('federal_id') === '') {
            throw ValidationException::withMessages([
                'profile' => 'Save this company\'s name, address, and federal ID on the USA offer profile before generating the letter.',
            ]);
        }
    }

    /**
     * @param  list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>  $line
     */
    private function paint(\GdImage $image, array $line, int $x, int $y, float $size, float $leading): int
    {
        $cursor = $x;

        foreach ($line as [$text, $bold, $color, $underline]) {
            $text = ltrim($text);

            if ($cursor !== $x && ! $this->attached($text)) {
                $cursor += (int) $this->width(' ', $size, false);
            }

            $this->draw($image, $text, $cursor, $y, $size, $bold, $color);

            if ($underline) {
                $colorIndex = imagecolorallocate($image, $color[0], $color[1], $color[2]);
                imageline($image, $cursor, $y + $this->px(2), $cursor + (int) $this->width($text, $size, $bold), $y + $this->px(2), $colorIndex);
            }

            $cursor += (int) $this->width($text, $size, $bold);
        }

        return $y + $this->px($leading);
    }

    /**
     * @param  list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>  $runs
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    private function tokens(array $runs): array
    {
        $words = [];

        foreach ($runs as [$text, $bold, $color, $underline]) {
            foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
                if ($word !== '') {
                    $words[] = [$word, $bold, $color, $underline];
                }
            }
        }

        return $words;
    }

    private function attached(string $word): bool
    {
        return str_starts_with($word, '.') || str_starts_with($word, ',') || str_starts_with($word, ';') || str_starts_with($word, ':');
    }

    private function bullet(\GdImage $image, string $text, int $y): int
    {
        $color = imagecolorallocate($image, 40, 40, 40);
        $width = $this->px(self::CONTENT_WIDTH - 22);
        $lines = $this->wrapPlain($text, self::BODY, false, $width);
        $first = true;

        foreach ($lines as $line) {
            if ($first) {
                imagefilledellipse($image, $this->px(self::MARGIN_X + 8), $y - $this->px(3.5), $this->px(3.2), $this->px(3.2), $color);
                $first = false;
            }

            $this->draw($image, $line, $this->px(self::MARGIN_X + 20), $y, self::BODY, false, [0, 0, 0]);
            $y += $this->px(self::LEADING);
        }

        return $y + $this->px(1);
    }

    private function bulletHeight(string $text): int
    {
        $lines = max(1, count($this->wrapPlain($text, self::BODY, false, $this->px(self::CONTENT_WIDTH - 22))));

        return ($lines * $this->px(self::LEADING)) + $this->px(1);
    }

    /**
     * @param  list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>  $runs
     * @return list<list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>>
     */
    private function wrappedRuns(array $runs, float $size, int $width): array
    {
        $words = $this->tokens($runs);
        $lines = [];
        $line = [];
        $lineWidth = 0.0;

        foreach ($words as $word) {
            $piece = ($line === [] || $this->attached($word[0]) ? '' : ' ').$word[0];
            $pieceWidth = $this->width($piece, $size, $word[1]);

            if ($line !== [] && $lineWidth + $pieceWidth > $width) {
                $lines[] = $line;
                $line = [$word];
                $lineWidth = $this->width($word[0], $size, $word[1]);

                continue;
            }

            if ($line === []) {
                $line[] = $word;
                $lineWidth = $pieceWidth;
            } else {
                $line[] = [$piece, $word[1], $word[2], $word[3]];
                $lineWidth += $pieceWidth;
            }
        }

        if ($line !== []) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wrapPlain(string $text, float $size, bool $bold, int $width): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && $this->width($candidate, $size, $bold) > $width) {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function draw(\GdImage $image, string $text, int $x, int $y, float $size, bool $bold, array $rgb): void
    {
        if ($text === '') {
            return;
        }

        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagettftext($image, $size * self::SCALE, 0, $x, $y, $color, $this->font($bold), $text);
    }

    private function width(string $text, float $size, bool $bold): float
    {
        $box = imagettfbbox($size * self::SCALE, 0, $this->font($bold), $text);

        return abs($box[2] - $box[0]);
    }

    private function paintLetterhead(\GdImage $canvas, string $path): void
    {
        $source = $this->raster($path);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $pageWidth = $this->px(self::PAGE_WIDTH);
        $pageHeight = $this->px(self::PAGE_HEIGHT);

        if ($sourceHeight > 0 && ($sourceWidth / $sourceHeight) >= 3) {
            $height = min($this->px(90), (int) round($pageWidth * ($sourceHeight / $sourceWidth)));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $pageWidth, max(1, $height), $sourceWidth, $sourceHeight);
            imagedestroy($source);

            return;
        }

        $scale = min($pageWidth / max(1, $sourceWidth), $pageHeight / max(1, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        imagecopyresampled(
            $canvas,
            $source,
            (int) (($pageWidth - $width) / 2),
            (int) (($pageHeight - $height) / 2),
            0,
            0,
            $width,
            $height,
            $sourceWidth,
            $sourceHeight,
        );
        imagedestroy($source);
    }

    private function paintSignature(\GdImage $canvas, int $x, int $y): void
    {
        if ($this->signature === null) {
            return;
        }

        $source = $this->raster($this->signature);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $boxWidth = $this->px(150);
        $boxHeight = $this->px(39);
        $scale = min($boxWidth / max(1, $sourceWidth), $boxHeight / max(1, $sourceHeight));
        imagecopyresampled(
            $canvas,
            $source,
            $x,
            $y,
            0,
            0,
            max(1, (int) round($sourceWidth * $scale)),
            max(1, (int) round($sourceHeight * $scale)),
            $sourceWidth,
            $sourceHeight,
        );
        imagedestroy($source);
    }

    private function raster(string $path): \GdImage
    {
        $bytes = file_get_contents($path);
        $image = is_string($bytes) ? imagecreatefromstring($bytes) : false;

        if ($image === false) {
            throw new \RuntimeException('The stationery image could not be read.');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    private function font(bool $bold): string
    {
        if (! function_exists('imagettftext') || ! function_exists('imagettfbbox')) {
            throw new \RuntimeException('USA offer PDFs require the PHP GD extension with FreeType.');
        }

        $candidates = $bold
            ? [
                resource_path('fonts/LiberationSans-Bold.ttf'),
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            ]
            : [
                resource_path('fonts/LiberationSans-Regular.ttf'),
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('The USA offer font file is missing.');
    }

    private function px(float $points): int
    {
        return (int) round($points * self::SCALE);
    }

    private function ordinal(int $day): string
    {
        $mod = $day % 100;

        if ($mod >= 11 && $mod <= 13) {
            return 'th';
        }

        return match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    private function usDate(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date('m/d/Y', $time);
    }

    private function text(mixed $value): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $clean;
    }
}
