<?php

namespace App\Services\Hrms;

use Illuminate\Validation\ValidationException;

class UsaOfferPdf
{
    private const PAGE_WIDTH = 612.0;

    private const PAGE_HEIGHT = 792.0;

    private const SCALE = 2.0;

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
        $y = $this->px(104);
        $this->date($image, $this->text($offer['issue_date'] ?? ''));
        $y = $this->block($image, $y, $this->fromLines(), true, 14.4);
        $y += $this->px(8);
        $name = $this->text($offer['candidate_name'] ?? '');
        $printedName = $name === '' ? '' : mb_strtoupper($name).(str_ends_with($name, ',') ? '' : ',');
        $to = array_filter([
            'To,',
            $printedName,
            $this->text($offer['candidate_address'] ?? '') !== '' ? mb_strtoupper($this->text($offer['candidate_address'] ?? '')) : '',
            $this->text($offer['candidate_email'] ?? ''),
            $this->text($offer['candidate_phone'] ?? ''),
        ]);
        $y = $this->block($image, $y, array_values($to), true, 14.4);
        $y += $this->px(10);
        $this->draw($image, 'Dear ', $this->px(54), $y, 12, false, [0, 0, 0]);
        $this->draw($image, $name.',', $this->px(54) + $this->width('Dear ', 12, false), $y, 12, true, [0, 0, 0]);
        $y += $this->px(20);
        $y = $this->rich($image, $this->offerRuns($offer), $this->px(54), $y, $this->px(504), 12, 15);
        $y += $this->px(12);
        $this->draw($image, 'Responsibilities:', $this->px(54), $y, 12, true, [0, 0, 0]);
        $y += $this->px(16);
        $responsibilities = is_array($offer['responsibilities'] ?? null) ? $offer['responsibilities'] : [];
        $limit = $this->px(744);

        foreach ($responsibilities as $item) {
            $line = $this->text($item);

            if ($line === '') {
                continue;
            }

            $height = $this->bulletHeight($line);

            if ($y + $height > $limit) {
                yield $image;
                $image = $this->chrome();
                $y = $this->px(108);
            }

            $y = $this->bullet($image, $line, $y);
        }

        if ($y + $this->closingHeight(false) > $limit) {
            yield $image;
            $image = $this->chrome();
            $y = $this->px(140);
            $this->drawClosing($image, $y, true);
        } else {
            $this->drawClosing($image, $y + $this->px(8), false);
        }

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

    private function closingHeight(bool $roomy): int
    {
        $report = $this->reportSentence();
        $lines = max(1, $this->lineCount($report, 12, false, $this->px(504)));
        $afterReport = $roomy ? 28 : 14;
        $afterSincerely = $roomy ? 20 : 16;
        $afterSignature = $roomy ? 14 : 10;
        $line = 16;
        $beforeAcceptance = $roomy ? 28 : 14;
        $beforeLabels = $roomy ? 22 : 16;

        return ($lines * $this->px(16))
            + $this->px($afterReport + $afterSincerely + 39 + $afterSignature + ($line * 3) + $beforeAcceptance + $line + $beforeLabels + $line + 8);
    }

    private function drawClosing(\GdImage $image, int $y, bool $roomy): void
    {
        $y = $this->rich($image, $this->reportRuns(), $this->px(54), $y, $this->px(504), 12, 16);
        $y += $this->px($roomy ? 28 : 14);
        $this->draw($image, 'Sincerely,', $this->px(54), $y, 12, false, [0, 0, 0]);
        $y += $this->px($roomy ? 20 : 16);
        $this->paintSignature($image, $this->px(54), $y);
        $y += $this->px(39 + ($roomy ? 14 : 10));
        $this->draw($image, $this->signatoryLine(), $this->px(54), $y, 12, false, [0, 0, 0]);
        $y += $this->px(16);
        $this->draw($image, 'Phone No: ', $this->px(54), $y, 12, false, [0, 0, 0]);
        $this->draw($image, $this->field('hr_phone').'.', $this->px(54) + $this->width('Phone No: ', 12, false), $y, 12, true, [0, 0, 0]);
        $y += $this->px(16);
        $this->draw($image, mb_strtoupper($this->field('company_name')), $this->px(54), $y, 12, true, [0, 0, 0]);
        $y += $this->px($roomy ? 28 : 14);
        $this->draw($image, 'I have read this offer and accept the terms of appointment described herein.', $this->px(54), $y, 12, false, [0, 0, 0]);
        $y += $this->px($roomy ? 22 : 16);
        $this->draw($image, 'Employee Signature:', $this->px(54), $y, 12, true, [0, 0, 0]);
        $this->draw($image, 'Date:', $this->px(470), $y, 12, true, [0, 0, 0]);
    }

    private function lineCount(string $text, float $size, bool $bold, int $width): int
    {
        $lines = 1;
        $used = 0.0;

        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $piece = $used === 0.0 ? $word : ' '.$word;
            $pieceWidth = $this->width($piece, $size, $bold);

            if ($used > 0 && $used + $pieceWidth > $width) {
                $lines++;
                $used = $this->width($word, $size, $bold);

                continue;
            }

            $used += $pieceWidth;
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

        $footer = array_values(array_filter([
            $this->field('website'),
            $this->field('email'),
            $this->field('phone'),
            trim($this->field('address_line').' '.$this->field('city_line')),
        ], fn (string $line): bool => $line !== ''));
        $y = $this->px(752);

        foreach ($footer as $line) {
            $this->draw($image, $line, $this->px(54), $y, 8, false, [40, 40, 40]);
            $y += $this->px(11);
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
        $width = $this->width($lead, 12, true) + $this->width($suffix, 8, true) + $this->width($tail, 12, true);
        $x = $this->px(558) - $width;
        $y = $this->px(90.5);
        $this->draw($image, $lead, $x, $y, 12, true, [0, 0, 0]);
        $x += $this->width($lead, 12, true);
        $this->draw($image, $suffix, $x, $y - $this->px(5), 8, true, [0, 0, 0]);
        $x += $this->width($suffix, 8, true);
        $this->draw($image, $tail, $x, $y, 12, true, [0, 0, 0]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function block(\GdImage $image, int $y, array $lines, bool $bold, float $leading = 16): int
    {
        foreach ($lines as $line) {
            $this->draw($image, $line, $this->px(54), $y, 12, $bold, [0, 0, 0]);
            $y += $this->px($leading);
        }

        return $y;
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
            ['This letter is to confirm that '.$this->field('company_name').', with Federal Employer Identification Number (EIN) '.$this->field('federal_id').', is delighted to offer you the position of ', false, [0, 0, 0], false],
            [$this->text($offer['designation_name'] ?? ''), true, [0, 0, 0], false],
            [' '.$pay, false, [0, 0, 0], false],
            [$amount, true, [0, 0, 0], false],
            ['. You will be working ', false, [0, 0, 0], false],
            [$this->text($offer['working_hours'] ?? ''), true, [0, 0, 0], false],
            [' in a '.$this->text($offer['work_arrangement'] ?? '').', with a start date of ', false, [0, 0, 0], false],
            [$start, true, [0, 0, 0], false],
            ['. This employment will be associated with your employment authorization status under your academic program at ', false, [0, 0, 0], false],
            [$this->text($offer['university'] ?? ''), true, [0, 0, 0], false],
            ['. We look forward to your contributions and are excited about the skills and expertise you will bring to our team.', false, [0, 0, 0], false],
        ];
        $employment = $this->text($offer['employment_type'] ?? '');

        if ($employment !== '') {
            $runs[] = [' Employment type: '.$employment.'.', false, [0, 0, 0], false];
        }

        return $runs;
    }

    /**
     * @return list<string>
     */
    private function fromLines(): array
    {
        return array_values(array_filter([
            'From,',
            $this->field('company_name').',',
            $this->field('address_line') !== '' ? $this->field('address_line').',' : '',
            $this->field('city_line') !== '' ? $this->field('city_line').',' : '',
            $this->field('country_line'),
            $this->field('e_verify') !== '' ? 'E-Verify No: '.$this->field('e_verify') : '',
            'Federal ID (EIN): '.$this->field('federal_id'),
        ], fn (string $line): bool => $line !== ''));
    }

    private function reportSentence(): string
    {
        $who = trim($this->field('manager_name').($this->field('manager_title') !== '' ? ', '.$this->field('manager_title') : ''));
        $sentence = 'You will report your day-to-day activities directly to '.$who.'.';
        $email = $this->field('manager_email');
        $phone = $this->field('manager_phone');

        if ($email !== '' && $phone !== '') {
            return $sentence.' The manager can be reached by email at '.$email.' or by phone at '.$phone.'.';
        }

        if ($email !== '') {
            return $sentence.' The manager can be reached by email at '.$email.'.';
        }

        if ($phone !== '') {
            return $sentence.' The manager can be reached by phone at '.$phone.'.';
        }

        return $sentence;
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

    private function signatoryLine(): string
    {
        $title = $this->field('hr_title');
        $name = $this->field('hr_name');

        if ($title !== '' && $name !== '') {
            return $title.' – '.$name;
        }

        return $title !== '' ? $title : $name;
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
     * @param  list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>  $runs
     */
    private function rich(\GdImage $image, array $runs, int $x, int $y, int $width, float $size, float $leading = 17): int
    {
        $words = $this->tokens($runs);

        $line = [];
        $lineWidth = 0;

        foreach ($words as $word) {
            $piece = ($line === [] || $this->attached($word[0]) ? '' : ' ').$word[0];
            $pieceWidth = $this->width($piece, $size, $word[1]);

            if ($line !== [] && $lineWidth + $pieceWidth > $width) {
                $y = $this->paint($image, $line, $x, $y, $size, $leading);
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
            $y = $this->paint($image, $line, $x, $y, $size, $leading);
        }

        return $y;
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
        imagefilledellipse($image, $this->px(64), $y - $this->px(4), $this->px(3.5), $this->px(3.5), $color);

        return $this->rich($image, [[$text, false, [0, 0, 0], false]], $this->px(76), $y, $this->px(482), 12, 14.4);
    }

    private function bulletHeight(string $text): int
    {
        $width = $this->width($text, 12, false);
        $lines = max(1, (int) ceil($width / max(1, $this->px(470))));

        return $lines * $this->px(14.4);
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
        imagettftext($image, $size * self::SCALE * 0.92, 0, $x, $y, $color, $this->font($bold), $text);
    }

    private function width(string $text, float $size, bool $bold): float
    {
        $box = imagettfbbox($size * self::SCALE * 0.92, 0, $this->font($bold), $text);

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
        $windows = $bold ? 'C:/Windows/Fonts/calibrib.ttf' : 'C:/Windows/Fonts/calibri.ttf';

        if (is_file($windows)) {
            return $windows;
        }

        return $bold ? 'C:/Windows/Fonts/arialbd.ttf' : 'C:/Windows/Fonts/arial.ttf';
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
