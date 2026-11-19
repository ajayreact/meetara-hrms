<?php

namespace App\Services\Hrms;

/**
 * Editable USA appointment wording.
 *
 * The defaults are the sentences already printed by UsaOfferPdf. An unchanged
 * template keeps that renderer path, so generated letters stay the same.
 */
class UsaOfferAppointment
{
    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'subject' => '',
            'opening' => 'This letter is to confirm that {{company_name}}, with Federal Employer Identification Number (EIN) {{federal_id}}, is delighted to offer you the position of {{designation}}',
            'position' => '',
            'compensation' => '{{pay_phrase}}{{pay_amount}}',
            'working_hours' => 'You will be working {{working_hours}}',
            'work_arrangement' => 'in a {{work_arrangement}}',
            'joining_date' => 'with a start date of {{start_date}}',
            'employment_type' => 'Employment type: {{employment_type}}.',
            'conditions' => 'This employment will be associated with your employment authorization status under your academic program at {{university}}.',
            'closing_paragraph' => 'We look forward to your contributions and are excited about the skills and expertise you will bring to our team.',
            'responsibilities_heading' => 'Responsibilities',
            'reporting' => 'You will report your day-to-day activities directly to {{manager}}. The manager can be reached by email at {{manager_email}} or by phone at {{manager_phone}}.',
            'closing' => 'Sincerely,',
            'acceptance' => 'I have read this offer and accept the terms of appointment described herein.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::defaults());
    }

    /**
     * @return array<string, string>
     */
    public static function normalize(mixed $input): array
    {
        if (is_string($input) && $input !== '') {
            $decoded = json_decode($input, true);
            $input = is_array($decoded) ? $decoded : [];
        }

        $source = is_array($input) ? $input : [];
        $clean = [];

        foreach (self::defaults() as $key => $default) {
            if (! array_key_exists($key, $source)) {
                $clean[$key] = $default;

                continue;
            }

            $limit = in_array($key, ['subject', 'responsibilities_heading', 'closing'], true) ? 200 : 4000;
            $text = trim(preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r", "\n"], ' ', (string) $source[$key])) ?? '');
            $clean[$key] = mb_substr($text, 0, $limit);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    public static function isBuiltIn(array $template): bool
    {
        return self::normalize($template) === self::defaults();
    }

    /**
     * @param  array<string, mixed>|null  $template
     */
    public static function reportingIsDefault(?array $template): bool
    {
        if ($template === null) {
            return true;
        }

        return self::normalize($template)['reporting'] === self::defaults()['reporting'];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $profile
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    public static function bodyRuns(array $template, array $offer, array $profile): array
    {
        $template = self::normalize($template);
        $values = self::values($offer, $profile);
        $parts = [
            [$template['opening'], ''],
            [$template['compensation'], ' '],
            [$template['working_hours'], '. '],
            [$template['work_arrangement'], ' '],
            [$template['joining_date'], ', '],
            [$template['conditions'], '. '],
            [$template['closing_paragraph'], ' '],
        ];
        $runs = self::join($parts, $values);
        $employment = trim((string) ($offer['employment_type'] ?? ''));

        if ($employment !== '' && trim($template['employment_type']) !== '') {
            $piece = self::tokenRuns($template['employment_type'], $values);

            if ($piece !== []) {
                if ($runs !== []) {
                    $runs[] = [' ', false, [0, 0, 0], false];
                }

                array_push($runs, ...$piece);
            }
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $profile
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    public static function positionRuns(array $template, array $offer, array $profile): array
    {
        $template = self::normalize($template);

        return self::tokenRuns($template['position'], self::values($offer, $profile));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $profile
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    public static function reportingRuns(array $template, array $offer, array $profile): array
    {
        $template = self::normalize($template);

        return self::tokenRuns($template['reporting'], self::values($offer, $profile));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $profile
     */
    public static function plain(array $template, array $offer, array $profile): string
    {
        $parts = [];

        foreach (self::bodyRuns($template, $offer, $profile) as $run) {
            $parts[] = $run[0];
        }

        return implode('', $parts);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $parts
     * @param  array<string, string>  $values
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    private static function join(array $parts, array $values): array
    {
        $runs = [];

        foreach ($parts as [$text, $separator]) {
            $piece = self::tokenRuns($text, $values);

            if ($piece === []) {
                continue;
            }

            if ($runs !== []) {
                $runs[] = [$separator, false, [0, 0, 0], false];
            }

            array_push($runs, ...$piece);
        }

        return $runs;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{0: string, 1: bool, 2: array{0: int, 1: int, 2: int}, 3: bool}>
     */
    private static function tokenRuns(string $template, array $values): array
    {
        $template = trim($template);

        if ($template === '') {
            return [];
        }

        $parts = preg_split('/(\{\{[a-z0-9_]+\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $runs = [];

        foreach ($parts as $part) {
            if (preg_match('/^\{\{([a-z0-9_]+)\}\}$/', $part, $match) === 1) {
                $value = $values[$match[1]] ?? '';

                if ($value === '') {
                    continue;
                }

                $link = $match[1] === 'manager_email';
                $bold = $match[1] !== 'pay_phrase';
                $runs[] = [$value, $bold, $link ? [20, 78, 160] : [0, 0, 0], $link];

                continue;
            }

            if ($part !== '') {
                $runs[] = [$part, false, [0, 0, 0], false];
            }
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $profile
     * @return array<string, string>
     */
    private static function values(array $offer, array $profile): array
    {
        $field = static fn (string $key): string => trim((string) ($profile[$key] ?? ''));
        $offerField = static fn (string $key): string => trim((string) ($offer[$key] ?? ''));
        $title = $field('manager_title');
        $manager = trim($field('manager_name').($title !== '' ? ', '.$title : ''));
        $payPhrase = ($offer['pay_type'] ?? 'hourly') === 'annual'
            ? 'at an annual salary of '
            : 'at an hourly rate of ';

        return [
            'company_name' => $field('company_name'),
            'federal_id' => $field('federal_id'),
            'designation' => $offerField('designation_name'),
            'pay_phrase' => $payPhrase,
            'pay_amount' => '$'.number_format((float) ($offer['pay_amount'] ?? 0), 2).' USD',
            'working_hours' => $offerField('working_hours'),
            'work_arrangement' => $offerField('work_arrangement'),
            'start_date' => self::usDate($offerField('start_date')),
            'university' => $offerField('university'),
            'employment_type' => $offerField('employment_type'),
            'candidate_name' => $offerField('candidate_name'),
            'manager' => $manager,
            'manager_name' => $field('manager_name'),
            'manager_title' => $title,
            'manager_email' => $field('manager_email'),
            'manager_phone' => $field('manager_phone'),
            'hr_name' => $field('hr_name'),
            'hr_title' => $field('hr_title'),
            'hr_phone' => $field('hr_phone'),
            'e_verify' => $field('e_verify'),
        ];
    }

    private static function usDate(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date('m/d/Y', $time);
    }
}
