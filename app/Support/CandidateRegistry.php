<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class CandidateRegistry
{
    public const EAD_WINDOW = 90;

    public const PASSPORT_WINDOW = 180;

    /** @var list<string> */
    public const GENDERS = ['Male', 'Female'];

    /** @var list<string> */
    public const EMPLOYMENT_TYPES = ['Part-Time', 'Full-Time'];

    /** @var list<string> */
    public const WORK_LOCATIONS = ['Remote', 'Onsite'];

    /** @var list<string> */
    public const AUTHORIZATIONS = ['CPT', 'OPT', 'STEM OPT', 'H1', 'H4'];

    /** @var list<string> */
    public const YES_NO = ['Yes', 'No'];

    /** @var list<string> */
    public const WORK_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Not in Range'];

    /** @var list<string> */
    public const DATE_FIELDS = [
        'work_authorization_expires_on',
        'ead_expiry_date',
        'passport_expiry_date',
    ];

    /** @var list<string> */
    public const SECRET_FIELDS = ['ssn', 'account', 'routing', 'i94', 'ead_number'];

    /** @var array<string, string> */
    public const DOCUMENTS = [
        'passport' => 'Passport',
        'visa' => 'Visa',
        'i94' => 'I-94',
        'drivers_license' => "Driver's license",
        'ead' => 'EAD',
        'masters_degree' => "Master's degree",
        'resume' => 'Resume',
        'ssn_document' => 'SSN document',
        'w4' => 'W-4',
        'state_id' => 'State ID',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const EXPIRY_FILTERS = [
        'expired_ead' => 'Expired EAD',
        'ead_90' => 'EAD expiring within 90 days',
        'expired_passport' => 'Expired Passport',
        'passport_180' => 'Passport expiring within 180 days',
    ];

    /**
     * Checklist rows are manual. Uploads do not change these statuses.
     *
     * @var array<string, array{label: string, initial: string, complete: string, options: array<string, string>}>
     */
    public const CHECKLIST = [
        'offer_letter_released' => ['label' => 'Offer Letter Released', 'initial' => 'no', 'complete' => 'yes', 'options' => ['no' => 'No', 'yes' => 'Yes']],
        'signed_offer_letter_received' => ['label' => 'Signed Offer Letter Received', 'initial' => 'no', 'complete' => 'yes', 'options' => ['no' => 'No', 'yes' => 'Yes']],
        'e_verify_completed' => ['label' => 'E-Verify Completed', 'initial' => 'no', 'complete' => 'yes', 'options' => ['no' => 'No', 'yes' => 'Yes']],
        'i983_received' => ['label' => 'I-983 Received', 'initial' => 'pending', 'complete' => 'received', 'options' => ['pending' => 'Pending', 'received' => 'Received']],
        'drivers_license_verified' => ['label' => "Driver's License Verified", 'initial' => 'pending', 'complete' => 'verified', 'options' => ['pending' => 'Pending', 'verified' => 'Verified']],
        'ssn_verified' => ['label' => 'SSN Verified', 'initial' => 'pending', 'complete' => 'verified', 'options' => ['pending' => 'Pending', 'verified' => 'Verified']],
        'passport_verified' => ['label' => 'Passport Verified', 'initial' => 'pending', 'complete' => 'verified', 'options' => ['pending' => 'Pending', 'verified' => 'Verified']],
        'w4_verified' => ['label' => 'W-4 Verified', 'initial' => 'pending', 'complete' => 'verified', 'options' => ['pending' => 'Pending', 'verified' => 'Verified']],
        'i20_verified' => ['label' => 'Latest I-20 Verified', 'initial' => 'pending', 'complete' => 'verified', 'options' => ['pending' => 'Pending', 'verified' => 'Verified']],
        'state_id_verified' => ['label' => 'State ID Verified', 'initial' => 'pending', 'complete' => 'verified', 'options' => ['pending' => 'Pending', 'verified' => 'Verified']],
        'ead_verified' => ['label' => 'EAD Verified', 'initial' => 'pending', 'complete' => 'verified', 'options' => ['pending' => 'Pending', 'verified' => 'Verified']],
    ];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
    }

    /**
     * @return array{status: string, label: string, tone: string, days: int|null, date: string|null, detail: string}
     */
    public static function assess(?string $date, string $kind): array
    {
        $window = $kind === 'passport' ? self::PASSPORT_WINDOW : self::EAD_WINDOW;
        $windowLabel = $kind === 'passport' ? 'Expiring within 180 days' : 'Expiring within 90 days';

        if ($date === null || $date === '') {
            return ['status' => 'not_set', 'label' => 'Not set', 'tone' => 'alert-unset', 'days' => null, 'date' => null, 'detail' => ''];
        }

        $today = self::today();
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date, (string) config('app.timezone'));

        if ($day === false) {
            return ['status' => 'not_set', 'label' => 'Not set', 'tone' => 'alert-unset', 'days' => null, 'date' => null, 'detail' => ''];
        }

        $day = $day->startOfDay();
        $dateString = $day->toDateString();

        if ($day->lt($today)) {
            $days = self::calendarDays($day, $today);

            return ['status' => 'expired', 'label' => 'Expired', 'tone' => 'alert-expired', 'days' => $days, 'date' => $dateString, 'detail' => $days.' days past'];
        }

        $days = self::calendarDays($today, $day);

        if ($day->lte($today->addDays($window))) {
            return ['status' => 'expiring', 'label' => $windowLabel, 'tone' => 'alert-expiring', 'days' => $days, 'date' => $dateString, 'detail' => $days.' days remaining'];
        }

        return ['status' => 'clear', 'label' => 'Clear', 'tone' => 'alert-clear', 'days' => $days, 'date' => $dateString, 'detail' => $days.' days remaining'];
    }

    /**
     * Calendar-day distance. Comparing instants can be one day short when the
     * application timezone crosses daylight saving time.
     */
    private static function calendarDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from->toDateString(), 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to->toDateString(), 'UTC');

        if ($start === false || $end === false) {
            return 0;
        }

        return (int) $start->diffInDays($end, true);
    }

    public static function mask(string $field, ?string $last4): string
    {
        $last4 = (string) $last4;

        if ($last4 === '') {
            return '';
        }

        return $field === 'ssn' ? '***-**-'.$last4 : '****'.$last4;
    }

    public static function ownsPath(string $path, int $tenantId, int $recordId, string $folder): bool
    {
        $prefix = 'candidate-records/'.$tenantId.'/'.$recordId.'/'.$folder.'/';

        return str_starts_with($path, $prefix)
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && preg_match('#^'.preg_quote($prefix, '#').'[0-9a-f-]{36}\.(pdf|jpg|png|webp)$#', $path) === 1;
    }
}
