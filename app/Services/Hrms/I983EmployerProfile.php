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

    public function manages(User $actor): bool
    {
        return in_array($actor->applicationRole(), ['company_admin', 'hr'], true) && $actor->companyId() !== null;
    }

    /**
     * @return array{ready: bool, saved: bool, legalName: string, website: string, street: string, suite: string, city: string, state: string, zip: string, ein: string, employeeCount: string, naics: string, oversight: string, measures: string}
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
            'oversight' => self::DEFAULT_OVERSIGHT,
            'measures' => self::DEFAULT_MEASURES,
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
            'oversight' => $training['oversight'],
            'measures' => $training['measures'],
        ];
    }

    /**
     * @return array{oversight: string, measures: string}
     */
    public function trainingDefaults(int $companyId): array
    {
        $oversight = self::DEFAULT_OVERSIGHT;
        $measures = self::DEFAULT_MEASURES;
        $row = $this->row($companyId);

        if ($row !== null && $this->hasTrainingColumns()) {
            if ($row->oversight !== null) {
                $oversight = (string) $row->oversight;
            }

            if ($row->measures !== null) {
                $measures = (string) $row->measures;
            }
        }

        return ['oversight' => $oversight, 'measures' => $measures];
    }

    /**
     * Values copied onto a new I-983. A missing profile returns null so the caller can keep its previous suggestion.
     *
     * @return array{legal_name: string, website: ?string, street: string, suite: ?string, city: string, state: string, zip: string, ein: string, employee_count: int, naics: string}|null
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

    public function ready(): bool
    {
        return Schema::hasTable('i983_employer_profiles');
    }

    public function hasTrainingColumns(): bool
    {
        return $this->ready() && Schema::hasColumn('i983_employer_profiles', 'oversight') && Schema::hasColumn('i983_employer_profiles', 'measures');
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
