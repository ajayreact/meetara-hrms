<?php

namespace App\Services\Hrms;

use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;

class I983Pdf
{
    public const STUDENT_REQUIRED = [
        'student_name', 'email', 'school_recommending', 'school_degree', 'sevis_school_code',
        'dso_name', 'dso_email', 'dso_phone', 'sevis_id', 'opt_start', 'opt_end', 'major_cip',
        'degree_level', 'degree_awarded_on', 'ead',
    ];

    public const WORKING_REQUIRED = [
        ...self::STUDENT_REQUIRED,
        'student_role', 'employer_name', 'street', 'city', 'state', 'zip', 'ein', 'employee_count',
        'naics', 'opt_hours', 'salary', 'start_date', 'site_name', 'site_address', 'official_name',
        'official_title', 'official_email', 'official_phone',
    ];

    /**
     * Section 3 and the training-site address belong to the employer. Students never type them.
     *
     * @var list<string>
     */
    public const EMPLOYER_ADDRESS_KEYS = ['street', 'suite', 'city', 'state', 'zip', 'site_address'];

    /**
     * Employer-owned fields. A student-submitted PDF leaves these blank.
     * HR working, employer-signed, and final copies still print them.
     *
     * @var list<string>
     */
    public const EMPLOYER_PRINT_KEYS = [
        'employer_name', 'street', 'suite', 'website', 'city', 'state', 'zip',
        'ein', 'employee_count', 'naics', 'opt_hours', 'salary', 'start_date', 'other_compensation',
        'site_name', 'site_address', 'official_name', 'official_title', 'official_email', 'official_phone',
        'oversight', 'measures', 'remarks',
    ];

    /**
     * @param  array<string, string>  $values
     * @param  array{image: string, name_title: string, date: string, organization: string}|null  $signature
     * @param  array{image: string, printed_name: string, date: string}|null  $studentCertification
     */
    public function render(array $values, ?array $signature = null, ?array $studentCertification = null, bool $draft = false, bool $studentCopy = false): string
    {
        $values = $this->printedValues($values);
        $source = storage_path('app/private/i983/official-i983.pdf');
        $pdf = new Fpdi('P', 'pt', [612, 792]);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCompression(false);
        $count = $pdf->setSourceFile($source);

        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(0, 0, 0);

            foreach ($this->boxes() as $box) {
                if ($box['page'] !== $page) {
                    continue;
                }
                if ($studentCopy && in_array($box['key'], self::EMPLOYER_PRINT_KEYS, true)) {
                    continue;
                }
                $text = trim((string) ($values[$box['key']] ?? ''));
                $this->draw($pdf, $box, $text);
            }

            if ($page === 2 && ! $studentCopy) {
                $this->drawLines($pdf, $this->compensationLines(trim((string) ($values['other_compensation'] ?? ''))), 236, [202.54, 220.30, 238.30, 256.30]);
            }

            if ($page === 1) {
                $this->drawPriorDegreeMark($pdf, $values['prior_degree'] ?? null);
            }

            if ($page === 1 && $studentCertification !== null) {
                $this->drawStudentCertification($pdf, $studentCertification);
            }

            if (! $studentCopy && $signature !== null && ($page === 2 || $page === 4)) {
                $this->drawEmployerSignature($pdf, $page, $signature);
            }

            if ($draft) {
                $this->drawDraftBanner($pdf);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * Draw only the final-evaluation block on page 5 of an existing five-page PDF.
     * Pages 1–4 are copied. The source bytes are not modified.
     *
     * @param  array{date_from: string, date_to: string, narrative: string, student_name: string, student_date: string, student_image: string, employer_name: string, employer_date: string, employer_image: string}  $fields
     */
    public function overlayFinalEvaluation(string $bytes, array $fields): string
    {
        $this->assertFinalNarrative($fields['narrative']);
        $temp = tempnam(sys_get_temp_dir(), 'i983');

        if ($temp === false) {
            throw ValidationException::withMessages([
                'form' => 'The evaluation PDF could not be prepared.',
            ]);
        }

        file_put_contents($temp, $bytes);

        try {
            $pdf = new Fpdi('P', 'pt', [612, 792]);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetCompression(false);
            $count = $pdf->setSourceFile($temp);

            if ($count !== 5) {
                throw ValidationException::withMessages([
                    'form' => 'The I-983 does not have the five official pages, so the evaluation was not written.',
                ]);
            }

            for ($page = 1; $page <= $count; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);

                if ($page !== 5) {
                    continue;
                }

                $pdf->SetFont('Helvetica', '', 8);
                $pdf->SetTextColor(0, 0, 0);

                foreach ($this->finalEvaluationText($fields) as $field) {
                    $this->draw($pdf, $field, $field['text']);
                }

                $this->drawSignatureImage($pdf, $fields['student_image'], 128, 137.88);
                $this->drawSignatureImage($pdf, $fields['employer_image'], 248, 99.60);
            }

            return $pdf->Output('S');
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    public function assertFits(array $values, string $mode): void
    {
        $values = $this->printedValues($values);
        $source = storage_path('app/private/i983/official-i983.pdf');

        if (! is_file($source)) {
            throw ValidationException::withMessages([
                'form' => 'The official I-983 form is not available, so no PDF was generated.',
            ]);
        }

        $errors = [];
        $required = match ($mode) {
            'working' => self::WORKING_REQUIRED,
            'draft' => [],
            default => self::STUDENT_REQUIRED,
        };
        $measure = new \FPDF('P', 'pt', [612, 792]);
        $measure->SetFont('Helvetica', '', 8);

        foreach ($this->boxes() as $box) {
            if ($mode === 'student' && in_array($box['key'], self::EMPLOYER_PRINT_KEYS, true)) {
                continue;
            }

            $text = trim((string) ($values[$box['key']] ?? ''));

            if ($text === '') {
                if (in_array($box['key'], $required, true)) {
                    $errors[$box['key']] = 'Enter '.$box['label'].' before generating the official form.';
                }

                continue;
            }

            if ($box['key'] === 'email' || $box['key'] === 'dso_email') {
                if ($this->emailSize($measure, $text, $box['w']) === null) {
                    $errors[$box['key']] = $box['label'].' does not fit in its box on the official form. Shorten it.';
                }

                $measure->SetFont('Helvetica', '', 8);

                continue;
            }

            if ($box['key'] === 'site_address' && $mode === 'working' && preg_match('/^(.+),\s*([^,]+),\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/', $text) !== 1) {
                $errors['site_address'] = 'Enter the training site as street, city, state, and ZIP. This address can differ from the employer\'s registered address.';

                continue;
            }

            $lines = $this->wrap($measure, $text, $box['w']);
            $max = max(1, (int) floor($box['h'] / 10));

            if ($lines === null || count($lines) > $max) {
                $errors[$box['key']] = $box['label'].' does not fit in its box on the official form. Shorten it.';
            }
        }

        $compensation = trim((string) ($values['other_compensation'] ?? ''));

        if ($mode !== 'student' && $compensation !== '') {
            $lines = $this->wrap($measure, $compensation, 332);

            if ($lines === null || count($lines) > 4) {
                $errors['other_compensation'] = 'Other compensation does not fit on the four official lines. Shorten it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Employer review and the final PDF measure these boxes. The stored text is left unchanged.
     *
     * @param  array<string, string>  $values
     */
    public function assertEmployerAddressLayout(array $values): void
    {
        if (! is_file(storage_path('app/private/i983/official-i983.pdf'))) {
            return;
        }

        $errors = [];
        $measure = new \FPDF('P', 'pt', [612, 792]);
        $measure->SetFont('Helvetica', '', 8);

        foreach ($this->boxes() as $box) {
            if (! in_array($box['key'], self::EMPLOYER_ADDRESS_KEYS, true)) {
                continue;
            }

            $text = trim((string) ($values[$box['key']] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($box['key'] === 'site_address' && preg_match('/^(.+),\s*([^,]+),\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/', $text) !== 1) {
                $errors['site_address'] = 'Enter the training site as street, city, state, and ZIP. This address can differ from the employer\'s registered address.';

                continue;
            }

            if ($this->employerAddressDeferred($measure, $box, $text)) {
                $errors[$box['key']] = $box['label'].' does not fit in its box on the official form. Shorten it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{key: string, label: string, page: int, x: float, y: float, w: float, h: float}  $box
     */
    private function employerAddressDeferred(\FPDF $pdf, array $box, string $text): bool
    {
        if ($text === '' || ! in_array($box['key'], self::EMPLOYER_ADDRESS_KEYS, true)) {
            return false;
        }

        $lines = $this->wrap($pdf, $text, $box['w']);
        $max = max(1, (int) floor($box['h'] / 10));

        return $lines === null || count($lines) > $max;
    }

    /**
     * @param  array{image: string, name_title: string, date: string, organization: string}  $signature
     */
    public function assertSignatureFits(array $signature): void
    {
        $errors = [];
        $measure = new \FPDF('P', 'pt', [612, 792]);
        $measure->SetFont('Helvetica', '', 8);

        foreach ($this->signatureText($signature) as $box) {
            $lines = $this->wrap($measure, $box['text'], $box['w']);
            $max = max(1, (int) floor($box['h'] / 10));

            if ($box['text'] === '' || $lines === null || count($lines) > $max) {
                $errors[$box['key']] = $box['label'].' does not fit in its box on the official form. Shorten it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A draft banner sits in the bottom margin. It does not move any official field.
     */
    private function drawDraftBanner(Fpdi $pdf): void
    {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(153, 0, 0);
        $pdf->Text(36, 784, 'DRAFT - NOT FOR SUBMISSION');
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Section 2 is drawn only for a stored student certification. Working copies
     * without one keep the three official lines blank.
     *
     * @param  array{image: string, printed_name: string, date: string}  $certification
     */
    public function assertStudentCertificationFits(array $certification): void
    {
        $measure = new \FPDF('P', 'pt', [612, 792]);
        $measure->SetFont('Helvetica', '', 8);
        $errors = [];

        foreach ($this->studentCertificationText($certification) as $box) {
            $lines = $this->wrap($measure, $box['text'], $box['w']);

            if ($box['text'] === '' || $lines === null || count($lines) > 1) {
                $errors[$box['key']] = $box['label'].' does not fit in its box on the official form. Shorten it.';
            }
        }

        if (@getimagesize($certification['image']) === false) {
            $errors['signature'] = 'The signature image could not be placed on the official form.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{key: string, label: string, page: int, x: float, y: float, w: float, h: float}  $box
     */
    private function draw(Fpdi $pdf, array $box, string $text): void
    {
        if ($text === '') {
            return;
        }

        if ($box['key'] === 'email' || $box['key'] === 'dso_email') {
            $this->drawEmail($pdf, $box, $text);

            return;
        }

        $lines = $this->wrap($pdf, $text, $box['w']);
        $max = max(1, (int) floor($box['h'] / 10));

        if ($lines === null || count($lines) > $max) {
            throw ValidationException::withMessages([
                $box['key'] => $box['label'].' does not fit in its box on the official form. Shorten it.',
            ]);
        }

        $this->drawLines($pdf, $lines, $box['x'], [$box['y']]);
    }

    /**
     * Student and DSO emails stay on their official baselines and on one line.
     * Helvetica drops from 8 points in half-point steps, down to 6 points,
     * only when the address is wider than its field.
     *
     * @param  array{key: string, label: string, page: int, x: float, y: float, w: float, h: float}  $box
     */
    private function drawEmail(Fpdi $pdf, array $box, string $text): void
    {
        if ($this->emailSize($pdf, $text, $box['w']) === null) {
            $pdf->SetFont('Helvetica', '', 8);

            throw ValidationException::withMessages([
                $box['key'] => $box['label'].' does not fit in its box on the official form. Shorten it.',
            ]);
        }

        $pdf->Text($box['x'], $box['y'], $text);
        $pdf->SetFont('Helvetica', '', 8);
    }

    private function emailSize(\FPDF $pdf, string $text, float $width): ?float
    {
        $measure = new \FPDF('P', 'pt', [612, 792]);
        $size = 8.0;
        $fitted = null;

        while ($size >= 5.99) {
            $measure->SetFont('Helvetica', '', $size);

            if ($measure->GetStringWidth($text) <= $width + 0.01) {
                $fitted = $size;

                break;
            }

            $size = round($size - 0.5, 1);
        }

        $pdf->SetFont('Helvetica', '', $fitted ?? 8);

        return $fitted;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<float>  $tops
     */
    private function drawLines(Fpdi $pdf, array $lines, float $x, array $tops): void
    {
        foreach ($lines as $index => $line) {
            $pdf->Text($x, $tops[$index] ?? ($tops[0] + ($index * 10)), $line);
        }
    }

    /**
     * @return list<string>|null
     */
    private function wrap(\FPDF $pdf, string $text, float $width): ?array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            if ($pdf->GetStringWidth($word) > $width + 0.01) {
                return null;
            }

            $trial = $current === '' ? $word : $current.' '.$word;

            if ($pdf->GetStringWidth($trial) <= $width + 0.01) {
                $current = $trial;

                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function compensationLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $pdf = new \FPDF('P', 'pt', [612, 792]);
        $pdf->SetFont('Helvetica', '', 8);

        $lines = $this->wrap($pdf, $text, 332);

        if ($lines === null || count($lines) > 4) {
            throw ValidationException::withMessages([
                'other_compensation' => 'Other compensation does not fit on the four official lines. Shorten it.',
            ]);
        }

        return $lines;
    }

    /**
     * @param  array{image: string, printed_name: string, date: string}  $certification
     */
    private function drawStudentCertification(Fpdi $pdf, array $certification): void
    {
        $this->drawSignatureImage($pdf, $certification['image'], 144, 195.8);
        $pdf->SetFont('Helvetica', '', 8);

        foreach ($this->studentCertificationText($certification) as $field) {
            $this->draw($pdf, $field, $field['text']);
        }
    }

    /**
     * @param  array{image: string, printed_name: string, date: string}  $certification
     * @return list<array{key: string, label: string, page: int, x: float, y: float, w: float, h: float, text: string}>
     */
    private function studentCertificationText(array $certification): array
    {
        return [
            [...$this->at(1, 'student_printed_name', 'Printed name of student', 144, 404, 173.9), 'text' => $certification['printed_name']],
            [...$this->at(1, 'student_certification_date', 'Certification date', 491, 560, 173.9), 'text' => $certification['date']],
        ];
    }

    /**
     * @param  array{image: string, name_title: string, date: string, organization: string}  $signature
     */
    private function drawEmployerSignature(Fpdi $pdf, int $page, array $signature): void
    {
        $this->drawSignatureImage($pdf, $signature['image'], 294, $page === 2 ? 120.0 : 468.0);

        foreach ($this->signatureText($signature) as $field) {
            if ($field['page'] !== $page) {
                continue;
            }

            $this->draw($pdf, $field, $field['text']);
        }
    }

    /**
     * @param  array{image: string, name_title: string, date: string, organization: string}  $signature
     * @return list<array{key: string, label: string, page: int, x: float, y: float, w: float, h: float, text: string}>
     */
    private function signatureText(array $signature): array
    {
        return [
            [...$this->at(2, 'signatory_name', 'Signatory name and title', 320, 560, 97.8), 'text' => $signature['name_title']],
            [...$this->at(2, 'signature_date', 'Signature date', 126, 188, 73.8), 'text' => $signature['date']],
            [...$this->at(2, 'employing_organization', 'Employing organization', 372, 560, 73.8), 'text' => $signature['organization']],
            [...$this->at(4, 'signatory_name', 'Signatory name and title', 320, 560, 451.8), 'text' => $signature['name_title']],
            [...$this->at(4, 'signature_date', 'Signature date', 126, 188, 433.8), 'text' => $signature['date']],
        ];
    }

    private function drawSignatureImage(Fpdi $pdf, string $path, float $left, float $line): void
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ValidationException::withMessages([
                'signature' => 'The signature image could not be placed on the official form.',
            ]);
        }

        $trimmed = $this->trimmedSignature($path);
        $info = @getimagesize($trimmed) ?: $info;
        $maxWidth = 170.0;
        $maxHeight = 11.0;
        $width = $maxWidth;
        $height = $width * ($info[1] / $info[0]);

        if ($height > $maxHeight) {
            $height = $maxHeight;
            $width = $height * ($info[0] / $info[1]);
        }

        $pdf->Image($trimmed, $left, 792 - ($line + 0.4 + $height), $width, $height);

        if ($trimmed !== $path) {
            @unlink($trimmed);
        }
    }

    private function trimmedSignature(string $path): string
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return $path;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => false,
        };

        if ($image === false) {
            return $path;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $alpha = ($color >> 24) & 127;

                if ($alpha > 96) {
                    continue;
                }

                $red = ($color >> 16) & 255;
                $green = ($color >> 8) & 255;
                $blue = $color & 255;

                if ($red > 245 && $green > 245 && $blue > 245) {
                    continue;
                }

                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }

        if ($maxX < $minX) {
            imagedestroy($image);

            return $path;
        }

        $cropped = imagecreatetruecolor($maxX - $minX + 1, $maxY - $minY + 1);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        imagefilledrectangle($cropped, 0, 0, imagesx($cropped), imagesy($cropped), imagecolorallocatealpha($cropped, 0, 0, 0, 127));
        imagecopy($cropped, $image, 0, 0, $minX, $minY, imagesx($cropped), imagesy($cropped));
        $empty = tempnam(sys_get_temp_dir(), 'i983sig');
        $target = $empty.'.png';
        @unlink($empty);
        imagepng($cropped, $target);
        imagedestroy($image);
        imagedestroy($cropped);

        return $target;
    }

    /**
     * The student form stores Bachelor, Master, and Doctorate. The official
     * degree line prints the possessive label. A value that is already written
     * out, including Doctorate and any other supported text, is printed unchanged.
     * A typographic apostrophe is normalized so Helvetica can draw it.
     */
    private function degreeLabel(string $stored): string
    {
        $text = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}", '`'], "'", trim($stored));

        return match ($text) {
            'Bachelor' => "Bachelor's",
            'Master' => "Master's",
            'Associate' => "Associate's",
            default => $text,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function printedValues(array $values): array
    {
        if (array_key_exists('degree_level', $values)) {
            $values['degree_level'] = $this->degreeLabel((string) $values['degree_level']);
        }

        return $values;
    }

    /**
     * Yes, true, and 1 draw a tick. No, false, and 0 draw a cross.
     * A missing or unrecognized answer stays visibly unanswered.
     */
    private function priorDegreeState(mixed $value): string
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        if ($value === null || $value === '') {
            return 'unknown';
        }

        if (in_array($value, [true, 1, '1', 'yes', 'true'], true)) {
            return 'yes';
        }

        if (in_array($value, [false, 0, '0', 'no', 'false'], true)) {
            return 'no';
        }

        return 'unknown';
    }

    /**
     * The official checkbox is the 10-point square at PDF user space
     * (128.52, 471.48)–(138.48, 481.56). These strokes use FPDF's top origin
     * and stay inside that square. Helvetica has no check or cross glyph.
     */
    private function drawPriorDegreeMark(Fpdi $pdf, mixed $value): void
    {
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(1.15);

        match ($this->priorDegreeState($value)) {
            'yes' => $this->drawPriorDegreeTick($pdf),
            'no' => $this->drawPriorDegreeCross($pdf),
            default => $this->drawPriorDegreeUnknown($pdf),
        };

        $pdf->SetLineWidth(0.567);
        $pdf->SetFont('Helvetica', '', 8);
    }

    private function drawPriorDegreeTick(Fpdi $pdf): void
    {
        $pdf->Line(130.70, 315.30, 132.70, 318.10);
        $pdf->Line(132.70, 318.10, 136.30, 312.80);
    }

    private function drawPriorDegreeCross(Fpdi $pdf): void
    {
        $pdf->Line(130.70, 312.70, 136.30, 318.30);
        $pdf->Line(130.70, 318.30, 136.30, 312.70);
    }

    private function drawPriorDegreeUnknown(Fpdi $pdf): void
    {
        $pdf->SetFont('Helvetica', 'B', 7);
        $mark = '?';
        $left = 128.52;
        $right = 138.48;
        $pdf->Text($left + (($right - $left - $pdf->GetStringWidth($mark)) / 2), 318.08, $mark);
    }

    /**
     * Baselines are the measured blank entry line inside each official cell, in PDF user space.
     * FPDI receives that baseline as a top-origin y. Multiline fields continue downward by 10 points.
     *
     * @return list<array{key: string, label: string, page: int, x: float, y: float, w: float, h: float}>
     */
    private function boxes(): array
    {
        return [
            $this->at(1, 'student_name', 'Student Name', 40, 300, 646.4),
            $this->at(1, 'email', 'Student Email Address', 330, 566, 646.4),
            $this->at(1, 'school_recommending', 'School recommending STEM OPT', 36, 162, 595.6),
            $this->at(1, 'school_degree', 'School where the STEM degree was earned', 172, 320, 595.6),
            $this->at(1, 'sevis_school_code', 'SEVIS school code', 330, 566, 595.6),
            $this->at(1, 'dso_name', 'DSO name', 36, 162, 549.5),
            $this->at(1, 'dso_email', 'DSO email', 127, 226, 549.5),
            $this->at(1, 'dso_phone', 'DSO phone', 234, 320, 549.5),
            $this->at(1, 'sevis_id', 'SEVIS ID', 330, 398, 549.5),
            $this->at(1, 'opt_start', 'STEM OPT start', 438, 506, 572.0),
            $this->at(1, 'opt_end', 'STEM OPT end', 430, 496, 559.8),
            $this->at(1, 'major_cip', 'Qualifying major and CIP code', 332, 566, 528.8),
            $this->at(1, 'degree_level', 'Degree level', 172, 420, 511.8),
            $this->at(1, 'degree_awarded_on', 'Date awarded', 156, 216, 493.1),
            $this->at(1, 'ead', 'Employment authorization number', 182, 330, 451.8),
            $this->at(2, 'employer_name', 'Employer Name', 42, 306, 718.8),
            $this->at(2, 'street', 'Street address', 318, 486, 718.8),
            $this->at(2, 'suite', 'Suite', 498, 570, 718.8),
            $this->at(2, 'website', 'Employer website', 42, 306, 694.8),
            $this->at(2, 'city', 'City', 318, 468, 694.8),
            $this->at(2, 'state', 'State', 480, 510, 694.8),
            $this->at(2, 'zip', 'ZIP code', 522, 570, 694.8),
            $this->at(2, 'ein', 'EIN', 42, 204, 660.5),
            $this->at(2, 'employee_count', 'Full-time employees', 216, 306, 660.5),
            $this->at(2, 'naics', 'NAICS code', 318, 570, 660.5),
            $this->at(2, 'opt_hours', 'OPT hours per week', 42, 204, 623.0),
            $this->at(2, 'salary', 'Annual compensation', 346, 566, 625.7),
            $this->at(2, 'start_date', 'Employment start date', 42, 204, 593.0),
            $this->at(3, 'student_name', 'Student Name', 264, 568, 730.68),
            $this->at(3, 'employer_name', 'Employer Name', 118, 568, 706.68),
            $this->at(3, 'site_name', 'Site Name', 92, 288, 664.80),
            $this->at(3, 'site_address', 'Site Address', 300, 568, 652.0),
            $this->at(3, 'official_name', 'Official name', 114, 288, 628.80),
            $this->at(3, 'official_title', 'Official title', 360, 568, 628.80),
            $this->at(3, 'official_email', 'Official email', 110, 288, 604.80),
            $this->at(3, 'official_phone', 'Official phone', 404, 568, 604.80),
            $this->at(3, 'student_role', 'Student Role', 46, 566, 532.0, 80),
            $this->at(3, 'goals', 'Goals and Objectives', 46, 566, 401.0, 80),
            $this->at(3, 'oversight', 'Employer Oversight', 46, 566, 280.0, 80),
            $this->at(3, 'measures', 'Measures and Assessments', 46, 566, 149.0, 80),
            $this->at(4, 'remarks', 'Additional Remarks', 46, 566, 733.0, 70),
        ];
    }

    /**
     * Measured from the official Form I-983 (7/16) page 5 content stream.
     * Date underlines are y=337.80. The student signature line is y=137.88.
     * The student name and date lines are y=115.80. The employer signature line
     * is y=99.60. The employer name and date lines are y=84.00.
     *
     * @param  array{date_from: string, date_to: string, narrative: string, student_name: string, student_date: string, employer_name: string, employer_date: string}  $fields
     * @return list<array{key: string, label: string, page: int, x: float, y: float, w: float, h: float, text: string}>
     */
    private function finalEvaluationText(array $fields): array
    {
        return [
            [...$this->at(5, 'final_date_from', 'Final evaluation from', 230, 301, 337.80), 'text' => $fields['date_from']],
            [...$this->at(5, 'final_date_to', 'Final evaluation to', 374, 444, 337.80), 'text' => $fields['date_to']],
            [...$this->finalNarrativeBox(), 'text' => $fields['narrative']],
            [...$this->at(5, 'final_student_name', 'Printed name of student', 142, 410, 115.80), 'text' => $fields['student_name']],
            [...$this->at(5, 'final_student_date', 'Student signature date', 496, 562, 115.80), 'text' => $fields['student_date']],
            [...$this->at(5, 'final_employer_name', 'Employer official name and title', 260, 410, 84.00), 'text' => $fields['employer_name']],
            [...$this->at(5, 'final_employer_date', 'Employer signature date', 495, 560, 84.00), 'text' => $fields['employer_date']],
        ];
    }

    /**
     * The final-evaluation narrative sits below the measured date line at y=337.80
     * and above the student signature line at y=137.88 on page 5.
     */
    public function assertFinalNarrative(string $narrative): void
    {
        $box = $this->finalNarrativeBox();
        $measure = new \FPDF('P', 'pt', [612, 792]);
        $measure->SetFont('Helvetica', '', 8);
        $lines = $this->wrap($measure, $narrative, $box['w']);
        $max = max(1, (int) floor($box['h'] / 10));

        if ($lines === null || count($lines) > $max) {
            throw ValidationException::withMessages([
                'narrative' => 'The progress narrative does not fit in the final evaluation box. Shorten it.',
            ]);
        }
    }

    /**
     * @return array{key: string, label: string, page: int, x: float, y: float, w: float, h: float}
     */
    private function finalNarrativeBox(): array
    {
        return $this->at(5, 'final_narrative', 'Final evaluation narrative', 46, 566, 326, 170);
    }

    /**
     * @return array{key: string, label: string, page: int, x: float, y: float, w: float, h: float}
     */
    private function at(int $page, string $key, string $label, float $left, float $right, float $baseline, float $depth = 10): array
    {
        return [
            'page' => $page,
            'key' => $key,
            'label' => $label,
            'x' => round($left, 2),
            'y' => round(792 - $baseline, 2),
            'w' => round($right - $left, 2),
            'h' => round($depth, 2),
        ];
    }
}
