<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class I983EmployerProfile
{
    public const DEFAULT_OVERSIGHT = 'The student will receive direct supervision and guidance from the assigned supervisor throughout the training period. The supervisor will provide project assignments, explain technical requirements, monitor daily activities, and review completed work. Regular meetings will be conducted to discuss progress, address technical challenges, and provide constructive feedback. The supervisor will also ensure that the student\'s work aligns with the training objectives and provides opportunities to apply and develop skills related to the STEM degree.';

    public const DEFAULT_MEASURES = 'The student\'s progress will be evaluated through regular performance reviews, project milestones, code reviews, and assessments of assigned technical tasks. The supervisor will assess the student\'s programming skills, software development knowledge, problem-solving abilities, database management, API integration, and overall project contributions. Feedback will be provided regularly to identify areas for improvement and measure progress toward the established training goals. Periodic evaluations will be conducted to ensure the student is developing the required technical competencies.';

    public const DEFAULT_STUDENT_ROLE = 'The student will complete supervised training in the employer\'s STEM work. The student will apply knowledge from the STEM degree, learn the tools and methods used on assigned projects, and develop practical skills in the field of study.';

    public const DEFAULT_GOALS = 'The training will develop the student\'s ability to apply STEM degree knowledge to the employer\'s work. The student will learn the professional practices used on assigned projects, complete supervised technical tasks, and show progress toward working independently in the field of study.';

    public const DEFAULT_WORK_ARRANGEMENT = 'remote';

    public const VELTRIX_OFFICIAL_EMAIL = 'hr@veltrixds.com';

    public const VELTRIX_OFFICIAL_PHONE = '+1(248) 755-3888';

    public function manages(User $actor): bool
    {
        return in_array($actor->applicationRole(), ['company_admin', 'hr'], true) && $actor->companyId() !== null;
    }

    /**
     * @return array{ready: bool, saved: bool, legalName: string, website: string, street: string, suite: string, city: string, state: string, zip: string, ein: string, employeeCount: string, naics: string, optHours: string, hourlyRate: string, salaryFrequency: string, annualCompensation: string, oversight: string, measures: string, studentRole: string, goals: string, siteName: string, siteAddress: string, workArrangement: string, officialEmail: string, officialPhone: string}
     */
    public function present(User $actor): array
    {
        $empty = [
            'ready' => $this->ready(),
            'saved' => false,
            'legalName' => '',
            'website' => '',
            'street' => '',
            'suite' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'ein' => '',
            'employeeCount' => '',
            'naics' => '',
            'optHours' => '',
            'hourlyRate' => '',
            'salaryFrequency' => '',
            'annualCompensation' => '',
            'oversight' => self::DEFAULT_OVERSIGHT,
            'measures' => self::DEFAULT_MEASURES,
            'studentRole' => self::DEFAULT_STUDENT_ROLE,
            'goals' => self::DEFAULT_GOALS,
            'siteName' => '',
            'siteAddress' => '',
            'workArrangement' => self::DEFAULT_WORK_ARRANGEMENT,
            'officialEmail' => '',
            'officialPhone' => '',
        ];

        if (! $this->ready() || ! $this->manages($actor)) {
            return $empty;
        }

        $row = $this->row((int) $actor->companyId());

        if ($row === null) {
            return $empty;
        }

        $address = $this->splitAddress(
            (string) $row->street,
            (string) ($row->suite ?? ''),
            (string) $row->city,
            (string) $row->state,
            (string) $row->zip,
        );
        $training = $this->trainingDefaults((int) $actor->companyId());
        $compensation = $this->compensationDefaults((int) $actor->companyId());

        return [
            'ready' => true,
            'saved' => true,
            'legalName' => (string) $row->legal_name,
            'website' => (string) ($row->website ?? ''),
            'street' => $address['street'],
            'suite' => $address['suite'],
            'city' => $address['city'],
            'state' => $address['state'],
            'zip' => $address['zip'],
            'ein' => $this->reveal($row->ein_ciphertext),
            'employeeCount' => (string) $row->employee_count,
            'naics' => (string) $row->naics,
            'optHours' => $compensation['opt_hours'] === null ? '' : (string) $compensation['opt_hours'],
            'hourlyRate' => $this->printedMoney($compensation['hourly_rate']),
            'salaryFrequency' => (string) ($compensation['salary_frequency'] ?? ''),
            'annualCompensation' => $this->printedMoney($compensation['annual_compensation']),
            'oversight' => $training['oversight'],
            'measures' => $training['measures'],
            'studentRole' => $training['student_role'],
            'goals' => $training['goals'],
            'siteName' => $training['site_name'],
            'siteAddress' => $training['site_address'],
            'workArrangement' => $training['work_arrangement'],
            'officialEmail' => $training['official_email'],
            'officialPhone' => $training['official_phone'],
        ];
    }

    /**
     * @return array{oversight: string, measures: string, student_role: string, goals: string, site_name: string, site_address: string, work_arrangement: string, official_email: string, official_phone: string}
     */
    public function trainingDefaults(int $companyId): array
    {
        $row = $this->row($companyId);
        $address = $row === null ? ['street' => '', 'city' => '', 'state' => '', 'zip' => ''] : $this->splitAddress(
            (string) $row->street,
            (string) ($row->suite ?? ''),
            (string) $row->city,
            (string) $row->state,
            (string) $row->zip,
        );
        $veltrix = $row !== null && str_contains(strtolower((string) $row->legal_name.' '.(string) ($row->website ?? '')), 'veltrix');
        $defaults = [
            'oversight' => self::DEFAULT_OVERSIGHT,
            'measures' => self::DEFAULT_MEASURES,
            'student_role' => self::DEFAULT_STUDENT_ROLE,
            'goals' => self::DEFAULT_GOALS,
            'site_name' => $row === null ? '' : (string) $row->legal_name,
            'site_address' => $this->siteAddress($address),
            'work_arrangement' => self::DEFAULT_WORK_ARRANGEMENT,
            'official_email' => $veltrix ? self::VELTRIX_OFFICIAL_EMAIL : '',
            'official_phone' => $veltrix ? self::VELTRIX_OFFICIAL_PHONE : '',
        ];

        if ($row === null || ! $this->hasTrainingColumns()) {
            return $defaults;
        }

        foreach (['oversight', 'measures', 'student_role', 'goals', 'site_name', 'site_address', 'work_arrangement', 'official_email', 'official_phone'] as $column) {
            if ($this->hasTrainingColumn($column) && $row->{$column} !== null) {
                $defaults[$column] = (string) $row->{$column};
            }
        }

        return $defaults;
    }

    /**
     * @param  array{street: string, city: string, state: string, zip: string}  $address
     */
    public function siteAddress(array $address): string
    {
        $region = trim($address['city']);
        $stateZip = trim(trim($address['state']).' '.trim($address['zip']));

        if ($region !== '' && $stateZip !== '') {
            $region .= ', '.$stateZip;
        } elseif ($stateZip !== '') {
            $region = $stateZip;
        }

        $street = trim($address['street']);

        if ($street === '') {
            return $region;
        }

        return $region === '' ? $street : $street.', '.$region;
    }

    /**
     * Annual salary printed in Section 3: hourly rate × weekly OPT hours × 52.
     */
    public static function annualFromHourly(string $hourly, int $hours): string
    {
        $cents = (int) round(((float) $hourly) * 100);

        return number_format(($cents * $hours * 52) / 100, 2, '.', '');
    }

    /**
     * Shared pay fields. Null means the company has not saved that part yet.
     *
     * @return array{opt_hours: ?int, hourly_rate: ?string, salary_frequency: ?string, annual_compensation: ?string}
     */
    public function compensationDefaults(int $companyId): array
    {
        $empty = [
            'opt_hours' => null,
            'hourly_rate' => null,
            'salary_frequency' => null,
            'annual_compensation' => null,
        ];
        $row = $this->row($companyId);

        if ($row === null || ! $this->hasTrainingColumn('opt_hours')) {
            return $empty;
        }

        $hours = $row->opt_hours === null ? null : (int) $row->opt_hours;
        $hourly = $row->hourly_rate === null || $row->hourly_rate === '' ? null : number_format((float) $row->hourly_rate, 2, '.', '');
        $frequency = $row->salary_frequency === null || $row->salary_frequency === '' ? null : (string) $row->salary_frequency;
        $annual = $hours !== null && $hourly !== null
            ? self::annualFromHourly($hourly, $hours)
            : ($row->annual_compensation === null || $row->annual_compensation === '' ? null : number_format((float) $row->annual_compensation, 2, '.', ''));

        if ($frequency === null && $annual !== null) {
            $frequency = 'year';
        }

        return [
            'opt_hours' => $hours,
            'hourly_rate' => $hourly,
            'salary_frequency' => $frequency,
            'annual_compensation' => $annual,
        ];
    }

    /**
     * Values copied onto a new I-983. A missing profile returns null so the caller can keep its previous suggestion.
     *
     * @return array{legal_name: string, website: ?string, street: string, suite: ?string, city: string, state: string, zip: string, ein: string, employee_count: int, naics: string, opt_hours: ?int, hourly_rate: ?string, salary_frequency: ?string, annual_compensation: ?string}|null
     */
    public function snapshot(int $companyId): ?array
    {
        $row = $this->row($companyId);

        if ($row === null) {
            return null;
        }

        $address = $this->splitAddress(
            (string) $row->street,
            (string) ($row->suite ?? ''),
            (string) $row->city,
            (string) $row->state,
            (string) $row->zip,
        );

        return [
            'legal_name' => (string) $row->legal_name,
            'website' => $row->website === null || $row->website === '' ? null : (string) $row->website,
            'street' => $address['street'],
            'suite' => $address['suite'] === '' ? null : $address['suite'],
            'city' => $address['city'],
            'state' => $address['state'],
            'zip' => $address['zip'],
            'ein' => $this->reveal($row->ein_ciphertext),
            'employee_count' => (int) $row->employee_count,
            'naics' => (string) $row->naics,
            ...$this->compensationDefaults($companyId),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(User $actor, array $values): void
    {
        if (! $this->manages($actor)) {
            abort(403);
        }

        if (! $this->ready()) {
            throw ValidationException::withMessages([
                'form' => 'U.S. employer information storage is not ready on this database.',
            ]);
        }

        $companyId = (int) $actor->companyId();
        $now = now();
        $existing = $this->row($companyId);
        $address = $this->splitAddress(
            (string) ($values['street'] ?? ''),
            (string) ($values['suite'] ?? ''),
            (string) ($values['city'] ?? ''),
            (string) ($values['state'] ?? ''),
            (string) ($values['zip'] ?? ''),
        );
        app(I983Pdf::class)->assertEmployerAddressLayout($address);
        $payload = [
            'legal_name' => trim((string) $values['legal_name']),
            'website' => $this->blank($values['website'] ?? null),
            'street' => $address['street'],
            'suite' => $this->blank($address['suite']),
            'city' => $address['city'],
            'state' => strtoupper($address['state']),
            'zip' => $address['zip'],
            'ein_ciphertext' => Crypt::encryptString(trim((string) $values['ein'])),
            'employee_count' => (int) $values['employee_count'],
            'naics' => trim((string) $values['naics']),
            'updated_by' => $actor->id,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ];

        if ($this->hasTrainingColumns() && array_key_exists('oversight', $values)) {
            $payload['oversight'] = $this->blank($values['oversight']);
            $payload['measures'] = $this->blank($values['measures'] ?? null);
        }

        foreach (['site_name', 'site_address', 'work_arrangement', 'official_email', 'official_phone', 'student_role', 'goals'] as $column) {
            if ($this->hasTrainingColumn($column) && array_key_exists($column, $values)) {
                $payload[$column] = $this->blank($values[$column]);
            }
        }

        if ($this->hasTrainingColumn('opt_hours') && array_key_exists('opt_hours', $values)) {
            $payload = [...$payload, ...$this->compensationColumns($values)];
        }

        DB::table('i983_employer_profiles')->updateOrInsert(['tenant_id' => $companyId], $payload);
    }

    /**
     * Put a single-line U.S. address into the Section 3 boxes. Values already stored in their own fields stay there.
     *
     * @return array{street: string, suite: string, city: string, state: string, zip: string}
     */
    public function splitAddress(string $street, string $suite = '', string $city = '', string $state = '', string $zip = ''): array
    {
        $suite = trim($suite);
        $city = trim($city);
        $state = strtoupper(trim($state));
        $zip = trim($zip);
        $street = trim(preg_replace('/\s+/', ' ', $this->stripCountry($street)) ?? '');

        if ($street === '' || ($city !== '' && $state !== '' && $zip !== '')) {
            return ['street' => $street, 'suite' => $suite, 'city' => $city, 'state' => $state, 'zip' => $zip];
        }

        $parts = $this->segments($street);

        if (count($parts) < 2) {
            return ['street' => $street, 'suite' => $suite, 'city' => $city, 'state' => $state, 'zip' => $zip];
        }

        $parsedStreet = '';
        $parsedSuite = $suite;
        $parsedCity = $city;
        $parsedState = $state;
        $parsedZip = $zip;

        foreach ($parts as $index => $part) {
            $suitePart = $this->suitePart($part);

            if ($parsedSuite === '' && $suitePart !== null) {
                $parsedSuite = $suitePart;

                continue;
            }

            $region = $this->stateZip($part);

            if ($region !== null) {
                $parsedState = $parsedState !== '' ? $parsedState : $region['state'];
                $parsedZip = $parsedZip !== '' ? $parsedZip : $region['zip'];

                continue;
            }

            if ($index === 0) {
                $peeled = $this->peelSuite($part);
                $parsedStreet = $peeled['street'];
                if ($parsedSuite === '' && $peeled['suite'] !== '') {
                    $parsedSuite = $peeled['suite'];
                }

                continue;
            }

            if ($parsedCity === '') {
                $parsedCity = $part;
            }
        }

        if ($parsedStreet === '' && $parsedCity === '' && $parsedState === '' && $parsedZip === '') {
            $parsedStreet = $street;
        }

        return [
            'street' => $parsedStreet,
            'suite' => $parsedSuite,
            'city' => $parsedCity,
            'state' => $parsedState,
            'zip' => $parsedZip,
        ];
    }

    /**
     * @return array{city: string, state: string, zip: string}
     */
    public function splitLocality(string $line): array
    {
        $address = $this->splitAddress($line);

        if ($address['street'] !== '' && $address['city'] === '' && preg_match('/\d/', $address['street']) !== 1) {
            $address['city'] = $address['street'];
            $address['street'] = '';
        }

        return ['city' => $address['city'], 'state' => $address['state'], 'zip' => $address['zip']];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{opt_hours: ?int, hourly_rate: ?string, salary_frequency: ?string, annual_compensation: ?string}
     */
    private function compensationColumns(array $values): array
    {
        $hours = $values['opt_hours'] ?? null;
        $hourly = $values['hourly_rate'] ?? null;

        if ($hours === null || $hours === '' || $hourly === null || $hourly === '') {
            return [
                'opt_hours' => null,
                'hourly_rate' => null,
                'salary_frequency' => null,
                'annual_compensation' => null,
            ];
        }

        $weekly = (int) $hours;
        $rate = number_format((float) $hourly, 2, '.', '');
        $frequency = in_array($values['salary_frequency'] ?? null, ['year', 'month', 'hour'], true)
            ? (string) $values['salary_frequency']
            : 'year';

        return [
            'opt_hours' => $weekly,
            'hourly_rate' => $rate,
            'salary_frequency' => $frequency,
            'annual_compensation' => self::annualFromHourly($rate, $weekly),
        ];
    }

    private function printedMoney(mixed $value): string
    {
        if ($value === null || $value === '' || (float) $value <= 0) {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }

    public function ready(): bool
    {
        return Schema::hasTable('i983_employer_profiles');
    }

    public function hasTrainingColumns(): bool
    {
        return $this->hasTrainingColumn('oversight') && $this->hasTrainingColumn('measures');
    }

    public function hasTrainingColumn(string $column): bool
    {
        return $this->ready() && Schema::hasColumn('i983_employer_profiles', $column);
    }

    private function stripCountry(string $value): string
    {
        $value = trim($value);
        $stripped = preg_replace('/,?\s*(United States of America|United States|U\.S\.A\.|USA)\.?$/i', '', $value);

        return trim(is_string($stripped) ? $stripped : $value, " \t,");
    }

    /**
     * @return list<string>
     */
    private function segments(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $part): bool => $part !== ''));
    }

    private function suitePart(string $part): ?string
    {
        if (preg_match('/^(?:suite|ste\.?|apt\.?|apartment|unit|#)\s+(.+)$/i', $part, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }

    /**
     * @return array{street: string, suite: string}
     */
    private function peelSuite(string $part): array
    {
        if (preg_match('/^(.*\d.*?)\s+((?:suite|ste\.?|apt\.?|apartment|unit)\s+.+)$/i', $part, $match) !== 1) {
            return ['street' => $part, 'suite' => ''];
        }

        return ['street' => trim($match[1]), 'suite' => $this->suitePart($match[2]) ?? trim($match[2])];
    }

    /**
     * @return array{state: string, zip: string}|null
     */
    private function stateZip(string $part): ?array
    {
        if (preg_match('/^([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/', $part, $match) === 1) {
            return ['state' => strtoupper($match[1]), 'zip' => $match[2]];
        }

        if (preg_match('/^([A-Za-z][A-Za-z .]+)\s+(\d{5}(?:-\d{4})?)$/', $part, $match) === 1) {
            $code = $this->stateCode(trim($match[1]));

            return $code === null ? null : ['state' => $code, 'zip' => $match[2]];
        }

        if (preg_match('/^(\d{5}(?:-\d{4})?)$/', $part, $match) === 1) {
            return ['state' => '', 'zip' => $match[1]];
        }

        $code = $this->stateCode($part);

        return $code === null ? null : ['state' => $code, 'zip' => ''];
    }

    private function stateCode(string $name): ?string
    {
        $states = [
            'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR', 'CALIFORNIA' => 'CA',
            'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE', 'DISTRICT OF COLUMBIA' => 'DC',
            'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI', 'IDAHO' => 'ID', 'ILLINOIS' => 'IL',
            'INDIANA' => 'IN', 'IOWA' => 'IA', 'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA',
            'MAINE' => 'ME', 'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN',
            'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE', 'NEVADA' => 'NV',
            'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM', 'NEW YORK' => 'NY',
            'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH', 'OKLAHOMA' => 'OK', 'OREGON' => 'OR',
            'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI', 'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD',
            'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA',
            'WASHINGTON' => 'WA', 'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
        ];

        return $states[strtoupper($name)] ?? null;
    }

    private function row(int $companyId): ?object
    {
        if (! $this->ready()) {
            return null;
        }

        return DB::table('i983_employer_profiles')->where('tenant_id', $companyId)->first();
    }

    private function reveal(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
