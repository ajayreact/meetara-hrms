<?php

namespace App\Support;

class CandidateForm
{
    public const NOTICE_VERSION = '2026-10-23.1';

    public const MAX_KILOBYTES = 5120;

    /**
     * Server settings that let this form accept one 5 MB document and nine
     * documents at that size, plus multipart overhead. These constants do not
     * change php.ini. The running PHP limits still have to be raised there.
     */
    public const RECOMMENDED_UPLOAD_MAX_BYTES = 6 * 1024 * 1024;

    public const RECOMMENDED_POST_MAX_BYTES = 64 * 1024 * 1024;

    public const REQUEST_TOO_LARGE = 'This submission is larger than the server can accept. Each document must be 5 MB or smaller. Remove or compress the files and submit again.';

    /**
     * Product defaults for this form. Driver's license is optional.
     * The other categories are required uploads for a complete submission.
     * These are not an immigration or legal determination.
     *
     * @var array<string, array{label: string, hint: string, required: bool}>
     */
    public const DOCUMENTS = [
        'passport' => ['label' => 'Passport copy', 'hint' => 'Clear copy of a valid passport.', 'required' => true],
        'visa' => ['label' => 'Visa copy', 'hint' => 'Clear copy of a valid visa.', 'required' => true],
        'i94' => ['label' => 'I-94', 'hint' => 'Latest I-94.', 'required' => true],
        'drivers_license' => ['label' => "Driver's license", 'hint' => 'Optional.', 'required' => false],
        'ead_front' => ['label' => 'EAD front', 'hint' => 'Front of the EAD card.', 'required' => true],
        'ead_back' => ['label' => 'EAD back', 'hint' => 'Back of the EAD card.', 'required' => true],
        'masters_degree' => ['label' => "Master's degree", 'hint' => 'Degree certificate and/or transcripts.', 'required' => true],
        'opt_i20' => ['label' => 'OPT I-20', 'hint' => 'OPT I-20.', 'required' => true],
        'resume' => ['label' => 'Resume / CV', 'hint' => 'Latest updated resume.', 'required' => true],
    ];

    /** @var array<string, string> */
    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var array<string, string> */
    public const STATUSES = [
        'pending_review' => 'Pending Review',
        'under_review' => 'Under Review',
        'changes_requested' => 'Changes Requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'pending_review' => ['under_review', 'changes_requested', 'approved', 'rejected'],
        'under_review' => ['changes_requested', 'approved', 'rejected'],
        'changes_requested' => ['under_review', 'rejected'],
        'approved' => [],
        'rejected' => [],
    ];

    /** @var array<string, string> */
    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'check' => 'Check',
        'card' => 'Card',
        'transfer' => 'Transfer',
        'other' => 'Other',
    ];

    /**
     * @return list<string>
     */
    public static function notice(): array
    {
        return [
            'This form collects identity, contact, bank, work-authorization, training, and supporting documents so authorized staff at the company that gave you this link can review a candidate submission.',
            'Authorized company personnel review the submission inside Meetara HRMS. The submission is not published.',
            'Questions about a submission or about privacy should be directed to the company that gave you this link. This form does not provide a separate privacy inbox.',
            'Submitting this form does not establish employment and does not guarantee approval.',
            'The notice wording, how long submissions are kept, and collection of Social Security numbers and bank details still need legal and privacy review before production use.',
        ];
    }

    public static function inputName(string $category): string
    {
        return 'document_'.$category;
    }

    /**
     * @return list<string>
     */
    public static function requiredDocuments(): array
    {
        return array_keys(array_filter(self::DOCUMENTS, fn (array $document): bool => $document['required']));
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['required', 'string', 'regex:/^[0-9+().\\-\\s]{7,30}$/'],
            'date_of_birth' => ['required', 'date', 'after:1900-01-01', 'before:today'],
            'current_address' => ['required', 'string', 'max:500'],
            'university_name' => ['required', 'string', 'max:191'],
            'job_title' => ['required', 'string', 'max:120'],
            'employment_type' => ['required', 'string', 'max:80'],
            'work_location' => ['required', 'string', 'max:120'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'hours_per_week' => ['required', 'integer', 'min:1', 'max:168'],
            'start_date' => ['required', 'date'],
            'experience' => ['required', 'string', 'max:80'],
            'responsibility_1' => ['required', 'string', 'max:1000'],
            'responsibility_2' => ['nullable', 'string', 'max:1000'],
            'responsibility_3' => ['nullable', 'string', 'max:1000'],
            'responsibility_4' => ['nullable', 'string', 'max:1000'],
            'responsibility_5' => ['nullable', 'string', 'max:1000'],
            'responsibility_6' => ['nullable', 'string', 'max:1000'],
            'bank_name' => ['required', 'string', 'max:80'],
            'account_number' => ['required', 'string', 'max:21'],
            'routing_number' => ['required', 'string', 'max:11'],
            'work_authorization' => ['required', 'string', 'max:80'],
            'i94_number' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,40}$/'],
            'ead_number' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,40}$/'],
            'work_authorization_expires_on' => ['required', 'date'],
            'ssn' => ['required', 'string', 'max:11'],
            'student_role' => ['required', 'string', 'max:5000'],
            'goals' => ['required', 'string', 'max:5000'],
            'oversight' => ['required', 'string', 'max:5000'],
            'evaluation_methods' => ['required', 'string', 'max:5000'],
            'consent' => ['accepted'],
            'tenant_id' => ['prohibited'],
            'status' => ['prohibited'],
            'payment_received' => ['prohibited'],
            'amount_paid' => ['prohibited'],
            'payment_method' => ['prohibited'],
            'training_agreement_sent' => ['prohibited'],
            'work_day' => ['prohibited'],
        ];

        foreach (self::DOCUMENTS as $category => $document) {
            $file = ['file', 'max:'.self::MAX_KILOBYTES];
            array_unshift($file, $document['required'] ? 'required' : 'nullable');
            $rules[self::inputName($category)] = $file;
        }

        return $rules;
    }

    /**
     * Fields that must not be flashed, logged, or shown on an error page.
     *
     * @return list<string>
     */
    public static function sensitiveKeys(): array
    {
        $keys = [
            'ssn',
            'bank_name',
            'account_number',
            'routing_number',
            'phone',
            'date_of_birth',
            'current_address',
            'i94_number',
            'ead_number',
            'captcha_token',
            'website',
            'password',
        ];

        foreach (array_keys(self::DOCUMENTS) as $category) {
            $keys[] = self::inputName($category);
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [
            'ssn.required' => 'Enter a Social Security number.',
            'consent.accepted' => 'Confirm that you have read the notice before submitting.',
        ];

        foreach (self::DOCUMENTS as $category => $document) {
            $name = self::inputName($category);
            $messages[$name.'.max'] = $document['label'].' must be 5 MB or smaller.';
            $messages[$name.'.uploaded'] = $document['label'].' could not be accepted. Each document must be 5 MB or smaller. If this file is already under 5 MB, the server upload limit is lower than this form allows.';
        }

        return $messages;
    }

    public static function ssnDigits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) !== 9) {
            return null;
        }

        $area = (int) substr($digits, 0, 3);
        $group = (int) substr($digits, 3, 2);
        $serial = (int) substr($digits, 5, 4);

        if ($area < 1 || $area === 666 || $area >= 900 || $group === 0 || $serial === 0) {
            return null;
        }

        return $digits;
    }

    public static function accountDigits(string $value): ?string
    {
        if (preg_match('/[^0-9\s-]/', $value) === 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) < 4 || strlen($digits) > 17) {
            return null;
        }

        return $digits;
    }

    public static function routingDigits(string $value): ?string
    {
        if (preg_match('/[^0-9\s-]/', $value) === 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) !== 9) {
            return null;
        }

        $weights = [3, 7, 1, 3, 7, 1, 3, 7, 1];
        $sum = 0;

        for ($index = 0; $index < 9; $index++) {
            $sum += $weights[$index] * (int) $digits[$index];
        }

        if ($sum === 0 || $sum % 10 !== 0) {
            return null;
        }

        return $digits;
    }

    public static function containsSensitiveNumber(string $value): bool
    {
        $compact = preg_replace('/[\s-]+/', '', $value) ?? '';

        return preg_match('/\d{9}/', $compact) === 1;
    }
}
