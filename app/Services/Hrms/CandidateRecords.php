<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\CandidateChecklistItem;
use App\Models\CandidatePayment;
use App\Models\CandidatePaymentReceipt;
use App\Models\CandidateRecord;
use App\Models\CandidateRecordDocument;
use App\Models\CandidateRecordEvent;
use App\Models\User;
use App\Support\CandidateForm;
use App\Support\CandidateRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CandidateRecords
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function list(User $actor, array $filters): array
    {
        $this->assertManage($actor);
        $paginator = $this->filtered($actor, $filters)
            ->with('checklistItems')
            ->paginate(20, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)))
            ->withQueryString();

        return [
            'rows' => collect($paginator->items())->map(fn (CandidateRecord $record) => $this->summary($record))->all(),
            'page' => $paginator->currentPage(),
            'pages' => max(1, $paginator->lastPage()),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CandidateRecord>
     */
    public function exportQuery(User $actor, array $filters): Builder
    {
        $this->assertExport($actor);

        return $this->filtered($actor, $filters)->with(['checklistItems', 'payments.receipts']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function register(User $actor, array $input): CandidateRecord
    {
        $this->assertManage($actor);
        $data = $this->validateProfile($input);
        $secrets = $this->secrets($input, true);
        $dates = $this->dates($input, true);

        return DB::transaction(function () use ($actor, $data, $secrets, $dates): CandidateRecord {
            $record = CandidateRecord::query()->create([
                ...$data,
                ...$this->secretColumns($secrets),
                ...$dates,
                'tenant_id' => $actor->companyId(),
                'reference' => $this->reference(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->seedChecklist($actor, $record);
            $this->event($actor, $record, 'registered', null, null, null, null);

            foreach (CandidateRegistry::DATE_FIELDS as $field) {
                if ($dates[$field] !== null) {
                    $this->event($actor, $record, 'expiry_changed', $field, null, $dates[$field], null);
                }
            }

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, CandidateRecord $record, array $input): void
    {
        $this->assertRecord($actor, $record);
        $data = $this->validateProfile($input);
        $secrets = $this->secrets($input, false);
        $columns = $this->secretColumns($secrets);

        DB::transaction(function () use ($actor, $record, $data, $secrets, $columns): void {
            $record->fill($data);
            foreach ($columns as $column => $value) {
                $record->{$column} = $value;
            }
            $record->updated_by = $actor->id;
            $record->save();
            $this->event($actor, $record, 'updated', null, null, null, null);

            foreach (array_keys($secrets) as $field) {
                $this->event($actor, $record, 'secret_replaced', $field, null, null, null);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateExpiries(User $actor, CandidateRecord $record, array $input): void
    {
        $this->assertRecord($actor, $record);
        $dates = $this->dates($input, false);

        DB::transaction(function () use ($actor, $record, $dates): void {
            foreach ($dates as $field => $value) {
                $previous = $record->{$field}?->toDateString();

                if ($previous === $value) {
                    continue;
                }

                $record->{$field} = $value;
                $this->event($actor, $record, 'expiry_changed', $field, $previous, $value, null);
            }

            $record->updated_by = $actor->id;
            $record->save();
        });
    }

    public function addDocument(User $actor, CandidateRecord $record, Request $request): void
    {
        $this->assertRecord($actor, $record);
        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(CandidateRegistry::DOCUMENTS))],
            'label' => ['nullable', 'string', 'max:80'],
            'file' => ['required', 'file', 'max:'.CandidateForm::MAX_KILOBYTES],
        ]);
        $category = (string) $validated['category'];
        $label = trim((string) ($validated['label'] ?? ''));

        if ($category === 'other' && $label === '') {
            throw ValidationException::withMessages(['label' => 'Enter a label for this document.']);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Choose a file to upload.']);
        }

        $stored = $this->storePrivateFile($file, (int) $record->tenant_id, (int) $record->id, 'documents');

        try {
            DB::transaction(function () use ($actor, $record, $category, $label, $file, $stored): void {
                CandidateRecordDocument::query()->create([
                    'tenant_id' => $record->tenant_id,
                    'candidate_record_id' => $record->id,
                    'category' => $category,
                    'label' => $category === 'other' ? $label : ($label !== '' ? $label : null),
                    'original_name' => $this->displayName($file),
                    'storage_path' => $stored['path'],
                    'mime' => $stored['mime'],
                    'size' => $stored['size'],
                    'uploaded_by' => $actor->id,
                ]);
                $this->event($actor, $record, 'document_uploaded', $category, null, null, $category === 'other' ? $label : null);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored['path']);

            throw $exception;
        }
    }

    public function downloadDocument(User $actor, CandidateRecord $record, int $documentId, Request $request): BinaryFileResponse|RedirectResponse
    {
        $this->assertRecord($actor, $record);
        $document = CandidateRecordDocument::query()
            ->where('candidate_record_id', $record->id)
            ->whereKey($documentId)
            ->first();
        abort_if($document === null || (int) $document->tenant_id !== (int) $record->tenant_id, 404);

        if ($document->category === 'ssn_document' && ! $this->passwordConfirmed($request)) {
            abort_if($request->expectsJson(), 423);

            return redirect()->guest(route('password.confirm'));
        }

        $response = $this->privateDownload(
            (string) $document->storage_path,
            (int) $record->tenant_id,
            (int) $record->id,
            'documents',
            (string) $document->original_name,
            (string) $document->mime,
        );
        $this->event($actor, $record, 'document_downloaded', (string) $document->category, null, null, null);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addPayment(User $actor, CandidateRecord $record, array $input): CandidatePayment
    {
        $this->assertRecord($actor, $record);
        $data = validator($input, [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'currency' => ['nullable', 'string', 'max:3'],
            'paid_on' => ['required', 'date'],
            'method' => ['required', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'USD')));

        if ($currency === '') {
            $currency = 'USD';
        }

        if ($currency !== 'USD') {
            throw ValidationException::withMessages(['currency' => 'Payments must be recorded in USD.']);
        }

        return DB::transaction(function () use ($actor, $record, $data, $currency): CandidatePayment {
            $payment = CandidatePayment::query()->create([
                'tenant_id' => $record->tenant_id,
                'candidate_record_id' => $record->id,
                'amount' => $this->money($data['amount']),
                'currency' => $currency,
                'paid_on' => $this->dateString($data['paid_on']),
                'method' => trim((string) $data['method']),
                'reference' => $this->nullableString($data['reference'] ?? null, 80),
                'notes' => $this->nullableString($data['notes'] ?? null, 2000),
                'recorded_by' => $actor->id,
            ]);
            $this->event($actor, $record, 'payment_recorded', 'payment', null, (string) $payment->amount, null);

            return $payment;
        });
    }

    public function addReceipt(User $actor, CandidateRecord $record, int $paymentId, Request $request): void
    {
        $this->assertRecord($actor, $record);
        $payment = $this->payment($record, $paymentId);
        $request->validate([
            'file' => ['required', 'file', 'max:'.CandidateForm::MAX_KILOBYTES],
        ]);
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Choose a receipt to upload.']);
        }

        $stored = $this->storePrivateFile($file, (int) $record->tenant_id, (int) $record->id, 'receipts');

        try {
            DB::transaction(function () use ($actor, $record, $payment, $file, $stored): void {
                CandidatePaymentReceipt::query()->create([
                    'tenant_id' => $record->tenant_id,
                    'candidate_payment_id' => $payment->id,
                    'original_name' => $this->displayName($file),
                    'storage_path' => $stored['path'],
                    'mime' => $stored['mime'],
                    'size' => $stored['size'],
                    'uploaded_by' => $actor->id,
                ]);
                $this->event($actor, $record, 'receipt_uploaded', 'payment', null, (string) $payment->id, null);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored['path']);

            throw $exception;
        }
    }

    public function downloadReceipt(User $actor, CandidateRecord $record, int $receiptId): BinaryFileResponse
    {
        $this->assertRecord($actor, $record);
        $receipt = CandidatePaymentReceipt::query()
            ->where('tenant_id', $record->tenant_id)
            ->whereKey($receiptId)
            ->first();
        $payment = $receipt === null ? null : CandidatePayment::query()
            ->where('candidate_record_id', $record->id)
            ->where('tenant_id', $record->tenant_id)
            ->whereKey($receipt->candidate_payment_id)
            ->first();
        abort_if($receipt === null || $payment === null, 404);
        $response = $this->privateDownload(
            (string) $receipt->storage_path,
            (int) $record->tenant_id,
            (int) $record->id,
            'receipts',
            (string) $receipt->original_name,
            (string) $receipt->mime,
        );
        $this->event($actor, $record, 'receipt_downloaded', 'payment', null, (string) $payment->id, null);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateChecklist(User $actor, CandidateRecord $record, int $itemId, array $input): void
    {
        $this->assertRecord($actor, $record);
        $item = CandidateChecklistItem::query()
            ->where('candidate_record_id', $record->id)
            ->where('tenant_id', $record->tenant_id)
            ->whereKey($itemId)
            ->first();
        abort_if($item === null, 404);
        $definition = CandidateRegistry::CHECKLIST[$item->item_key] ?? null;
        abort_if($definition === null, 404);
        $data = validator($input, [
            'status' => ['required', Rule::in(array_keys($definition['options']))],
            'remark' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $previous = (string) $item->status;
        $status = (string) $data['status'];
        $remark = $this->nullableString($data['remark'] ?? null, 2000);

        DB::transaction(function () use ($actor, $record, $item, $previous, $status, $remark): void {
            $item->status = $status;
            $item->remark = $remark;
            $item->updated_by = $actor->id;
            $item->save();
            $this->event($actor, $record, 'checklist_updated', (string) $item->item_key, $previous, $status, $remark);
        });
    }

    /**
     * @return array{value: string}
     */
    public function reveal(User $actor, CandidateRecord $record, string $field): array
    {
        $this->assertRecord($actor, $record);

        if (! in_array($field, CandidateRegistry::SECRET_FIELDS, true)) {
            throw ValidationException::withMessages(['field' => 'Choose a protected field.']);
        }

        $column = match ($field) {
            'ssn' => 'ssn_ciphertext',
            'account' => 'account_ciphertext',
            'routing' => 'routing_ciphertext',
            'i94' => 'i94_ciphertext',
            'ead_number' => 'ead_number_ciphertext',
        };
        try {
            $value = Crypt::decryptString((string) $record->{$column});
        } catch (DecryptException) {
            abort(404);
        }
        $this->event($actor, $record, 'revealed', $field, null, null, null);

        return ['value' => $value];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CandidateRecord $record): array
    {
        $record->load(['documents.uploadedBy', 'payments.receipts', 'payments.recordedBy', 'checklistItems.updatedBy', 'events.actor']);
        $ead = CandidateRegistry::assess($record->ead_expiry_date?->toDateString(), 'ead');
        $passport = CandidateRegistry::assess($record->passport_expiry_date?->toDateString(), 'passport');

        return [
            'id' => $record->id,
            'reference' => $record->reference,
            'personal' => [
                'firstName' => $record->first_name,
                'lastName' => $record->last_name,
                'gender' => $record->gender,
                'email' => $record->email,
                'phone' => $record->phone,
                'dateOfBirth' => $record->date_of_birth?->toDateString(),
                'currentAddress' => $record->current_address,
                'universityName' => $record->university_name,
                'ssnMask' => CandidateRegistry::mask('ssn', $record->ssn_last4),
            ],
            'employment' => [
                'jobTitle' => $record->job_title,
                'employmentType' => $record->employment_type,
                'workLocation' => $record->work_location,
                'hourlyRate' => (string) $record->hourly_rate,
                'hoursPerWeek' => (int) $record->hours_per_week,
                'startDate' => $record->start_date?->toDateString(),
                'experience' => $record->experience,
                'responsibility1' => $record->responsibility_1,
                'responsibility2' => $record->responsibility_2,
                'responsibility3' => $record->responsibility_3,
                'responsibility4' => $record->responsibility_4,
                'responsibility5' => $record->responsibility_5,
                'responsibility6' => $record->responsibility_6,
            ],
            'bank' => [
                'bankName' => $record->bank_name,
                'accountMask' => CandidateRegistry::mask('account', $record->account_last4),
                'routingMask' => CandidateRegistry::mask('routing', $record->routing_last4),
            ],
            'authorization' => [
                'workAuthorization' => $record->work_authorization,
                'i94Mask' => CandidateRegistry::mask('i94', $record->i94_last4),
                'eadMask' => CandidateRegistry::mask('ead_number', $record->ead_last4),
                'workAuthorizationExpiresOn' => $record->work_authorization_expires_on?->toDateString(),
            ],
            'training' => [
                'studentRole' => $record->student_role,
                'goals' => $record->goals,
                'oversight' => $record->oversight,
                'evaluationMethods' => $record->evaluation_methods,
            ],
            'statedPayment' => [
                'received' => $record->payment_received,
                'amount' => (string) $record->stated_amount,
                'method' => $record->stated_payment_method,
            ],
            'operations' => [
                'trainingAgreementSent' => $record->training_agreement_sent,
                'workDay' => $record->work_day,
            ],
            'ead' => $ead,
            'passport' => $passport,
            'completion' => $this->completion($record),
            'documents' => $record->documents->map(fn (CandidateRecordDocument $document) => [
                'id' => $document->id,
                'category' => $document->category,
                'label' => $document->label ?: (CandidateRegistry::DOCUMENTS[$document->category] ?? $document->category),
                'name' => $document->original_name,
                'password' => $document->category === 'ssn_document',
                'uploadedAt' => $document->created_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
            ])->values()->all(),
            'payments' => $record->payments->map(fn (CandidatePayment $payment) => [
                'id' => $payment->id,
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'paidOn' => $payment->paid_on?->toDateString(),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'receipts' => $payment->receipts->map(fn (CandidatePaymentReceipt $receipt) => [
                    'id' => $receipt->id,
                    'name' => $receipt->original_name,
                ])->values()->all(),
            ])->values()->all(),
            'checklist' => $record->checklistItems->sortBy(fn (CandidateChecklistItem $item) => array_search($item->item_key, array_keys(CandidateRegistry::CHECKLIST), true))->values()->map(function (CandidateChecklistItem $item) {
                $definition = CandidateRegistry::CHECKLIST[$item->item_key];

                return [
                    'id' => $item->id,
                    'key' => $item->item_key,
                    'label' => $definition['label'],
                    'status' => $item->status,
                    'statusLabel' => $definition['options'][$item->status] ?? $item->status,
                    'options' => $definition['options'],
                    'remark' => $item->remark,
                    'updatedAt' => $item->updated_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                    'updatedBy' => $item->updatedBy?->name,
                ];
            })->all(),
            'events' => $record->events->sortByDesc('id')->values()->map(fn (CandidateRecordEvent $event) => [
                'id' => $event->id,
                'detail' => $this->eventDetail($event),
                'at' => $event->created_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                'actor' => $event->actor?->name,
            ])->all(),
            'registeredAt' => $record->created_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
            'updatedAt' => $record->updated_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
        ];
    }

    public function ensureManage(User $actor): void
    {
        $this->assertManage($actor);
    }

    public function find(User $actor, int $id): CandidateRecord
    {
        $this->assertManage($actor);
        $record = CandidateRecord::query()->whereKey($id)->first();
        abort_if($record === null || (int) $record->tenant_id !== (int) $actor->companyId(), 404);

        return $record;
    }

    public function passwordConfirmed(Request $request): bool
    {
        $confirmed = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmed > 0 && (time() - $confirmed) <= (int) config('candidate.password_timeout');
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return [
            'genders' => CandidateRegistry::GENDERS,
            'employmentTypes' => CandidateRegistry::EMPLOYMENT_TYPES,
            'workLocations' => CandidateRegistry::WORK_LOCATIONS,
            'authorizations' => CandidateRegistry::AUTHORIZATIONS,
            'yesNo' => CandidateRegistry::YES_NO,
            'workDays' => CandidateRegistry::WORK_DAYS,
            'documents' => CandidateRegistry::DOCUMENTS,
            'expiryFilters' => CandidateRegistry::EXPIRY_FILTERS,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public function expiryFilters(array $filters): array
    {
        $selected = $filters['expiry'] ?? [];

        if (! is_array($selected)) {
            $selected = [$selected];
        }

        return array_values(array_filter($selected, fn ($value) => is_string($value) && array_key_exists($value, CandidateRegistry::EXPIRY_FILTERS)));
    }

    private function assertManage(User $actor): void
    {
        $permissions = CompanyRole::permissions($actor->applicationRole());
        abort_unless(($permissions['candidatesManage'] ?? false) === true && $actor->companyId() !== null, 403);
    }

    private function assertExport(User $actor): void
    {
        $permissions = CompanyRole::permissions($actor->applicationRole());
        abort_unless(($permissions['candidatesExport'] ?? false) === true && $actor->companyId() !== null, 403);
    }

    private function assertRecord(User $actor, CandidateRecord $record): void
    {
        $this->assertManage($actor);
        abort_unless((int) $record->tenant_id === (int) $actor->companyId(), 404);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CandidateRecord>
     */
    private function filtered(User $actor, array $filters): Builder
    {
        $query = CandidateRecord::query()->inCompany((int) $actor->companyId());
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('reference', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('job_title', 'like', $like);
            });
        }

        $selected = $this->expiryFilters($filters);

        if ($selected !== []) {
            $today = CandidateRegistry::today()->toDateString();
            $eadUntil = CandidateRegistry::today()->addDays(CandidateRegistry::EAD_WINDOW)->toDateString();
            $passportUntil = CandidateRegistry::today()->addDays(CandidateRegistry::PASSPORT_WINDOW)->toDateString();
            $query->where(function (Builder $inner) use ($selected, $today, $eadUntil, $passportUntil): void {
                foreach ($selected as $filter) {
                    $inner->orWhere(function (Builder $branch) use ($filter, $today, $eadUntil, $passportUntil): void {
                        match ($filter) {
                            'expired_ead' => $branch->whereDate('ead_expiry_date', '<', $today),
                            'ead_90' => $branch->whereDate('ead_expiry_date', '>=', $today)->whereDate('ead_expiry_date', '<=', $eadUntil),
                            'expired_passport' => $branch->whereDate('passport_expiry_date', '<', $today),
                            'passport_180' => $branch->whereDate('passport_expiry_date', '>=', $today)->whereDate('passport_expiry_date', '<=', $passportUntil),
                            default => $branch->whereRaw('1 = 0'),
                        };
                    });
                }
            });
        }

        return $query->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(CandidateRecord $record): array
    {
        return [
            'id' => $record->id,
            'reference' => $record->reference,
            'name' => $record->first_name.' '.$record->last_name,
            'email' => $record->email,
            'jobTitle' => $record->job_title,
            'completion' => $this->completion($record),
            'ead' => CandidateRegistry::assess($record->ead_expiry_date?->toDateString(), 'ead'),
            'passport' => CandidateRegistry::assess($record->passport_expiry_date?->toDateString(), 'passport'),
            'workAuthorizationExpiresOn' => $record->work_authorization_expires_on?->toDateString(),
        ];
    }

    private function completion(CandidateRecord $record): int
    {
        $complete = $record->checklistItems->filter(function (CandidateChecklistItem $item): bool {
            $definition = CandidateRegistry::CHECKLIST[$item->item_key] ?? null;

            return $definition !== null && $item->status === $definition['complete'];
        })->count();

        return (int) round(($complete / count(CandidateRegistry::CHECKLIST)) * 100);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateProfile(array $input): array
    {
        $data = validator($input, [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['required', Rule::in(CandidateRegistry::GENDERS)],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['required', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date'],
            'current_address' => ['required', 'string', 'max:2000'],
            'university_name' => ['required', 'string', 'max:191'],
            'job_title' => ['required', 'string', 'max:120'],
            'employment_type' => ['required', Rule::in(CandidateRegistry::EMPLOYMENT_TYPES)],
            'work_location' => ['required', Rule::in(CandidateRegistry::WORK_LOCATIONS)],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'hours_per_week' => ['required', 'integer', 'min:1', 'max:168'],
            'start_date' => ['required', 'date'],
            'experience' => ['required', 'string', 'max:80'],
            'responsibility_1' => ['required', 'string', 'max:5000'],
            'responsibility_2' => ['required', 'string', 'max:5000'],
            'responsibility_3' => ['required', 'string', 'max:5000'],
            'responsibility_4' => ['nullable', 'string', 'max:5000'],
            'responsibility_5' => ['required', 'string', 'max:5000'],
            'responsibility_6' => ['required', 'string', 'max:5000'],
            'bank_name' => ['required', 'string', 'max:80'],
            'work_authorization' => ['required', Rule::in(CandidateRegistry::AUTHORIZATIONS)],
            'student_role' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'string', 'max:5000'],
            'oversight' => ['nullable', 'string', 'max:5000'],
            'evaluation_methods' => ['nullable', 'string', 'max:5000'],
            'payment_received' => ['required', Rule::in(CandidateRegistry::YES_NO)],
            'stated_amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'stated_payment_method' => ['required', 'string', 'max:80'],
            'training_agreement_sent' => ['nullable', Rule::in(CandidateRegistry::YES_NO)],
            'work_day' => ['required', Rule::in(CandidateRegistry::WORK_DAYS)],
        ])->validate();

        return [
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'gender' => $data['gender'],
            'email' => trim((string) $data['email']),
            'phone' => trim((string) $data['phone']),
            'date_of_birth' => $this->optionalDate($data['date_of_birth'] ?? null),
            'current_address' => trim((string) $data['current_address']),
            'university_name' => trim((string) $data['university_name']),
            'job_title' => trim((string) $data['job_title']),
            'employment_type' => $data['employment_type'],
            'work_location' => $data['work_location'],
            'hourly_rate' => $this->money($data['hourly_rate']),
            'hours_per_week' => (int) $data['hours_per_week'],
            'start_date' => $this->dateString($data['start_date']),
            'experience' => trim((string) $data['experience']),
            'responsibility_1' => trim((string) $data['responsibility_1']),
            'responsibility_2' => trim((string) $data['responsibility_2']),
            'responsibility_3' => trim((string) $data['responsibility_3']),
            'responsibility_4' => $this->nullableString($data['responsibility_4'] ?? null, 5000),
            'responsibility_5' => trim((string) $data['responsibility_5']),
            'responsibility_6' => trim((string) $data['responsibility_6']),
            'bank_name' => trim((string) $data['bank_name']),
            'work_authorization' => $data['work_authorization'],
            'student_role' => $this->nullableString($data['student_role'] ?? null, 5000),
            'goals' => $this->nullableString($data['goals'] ?? null, 5000),
            'oversight' => $this->nullableString($data['oversight'] ?? null, 5000),
            'evaluation_methods' => $this->nullableString($data['evaluation_methods'] ?? null, 5000),
            'payment_received' => $data['payment_received'],
            'stated_amount' => $this->money($data['stated_amount']),
            'stated_payment_method' => trim((string) $data['stated_payment_method']),
            'training_agreement_sent' => ($data['training_agreement_sent'] ?? null) ?: null,
            'work_day' => $data['work_day'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function secrets(array $input, bool $required): array
    {
        $rules = $required
            ? ['required', 'string', 'max:40']
            : ['nullable', 'string', 'max:40'];
        $data = validator($input, [
            'ssn' => $rules,
            'account_number' => $rules,
            'routing_number' => $rules,
            'i94_number' => $rules,
            'ead_number' => $rules,
        ])->validate();
        $secrets = [];

        foreach ([
            'ssn' => $data['ssn'] ?? null,
            'account' => $data['account_number'] ?? null,
            'routing' => $data['routing_number'] ?? null,
            'i94' => $data['i94_number'] ?? null,
            'ead_number' => $data['ead_number'] ?? null,
        ] as $field => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                if ($required) {
                    throw ValidationException::withMessages([$this->secretInput($field) => 'This field is required.']);
                }

                continue;
            }

            $normalized = match ($field) {
                'ssn' => CandidateForm::ssnDigits($value),
                'account' => CandidateForm::accountDigits($value),
                'routing' => CandidateForm::routingDigits($value),
                default => $this->identityCode($value),
            };

            if ($normalized === null) {
                throw ValidationException::withMessages([
                    $this->secretInput($field) => 'Enter a valid value.',
                ]);
            }

            $secrets[$field] = $normalized;
        }

        return $secrets;
    }

    /**
     * @param  array<string, string>  $secrets
     * @return array<string, string>
     */
    private function secretColumns(array $secrets): array
    {
        $columns = [];

        foreach ($secrets as $field => $value) {
            $prefix = match ($field) {
                'ssn' => 'ssn',
                'account' => 'account',
                'routing' => 'routing',
                'i94' => 'i94',
                'ead_number' => 'ead',
            };
            $cipher = $field === 'ead_number' ? 'ead_number_ciphertext' : $prefix.'_ciphertext';
            $last4 = $field === 'ead_number' ? 'ead_last4' : $prefix.'_last4';
            $columns[$cipher] = Crypt::encryptString($value);
            $columns[$last4] = substr($value, -4);
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function dates(array $input, bool $creating): array
    {
        $rules = ['nullable', 'date'];
        $keys = CandidateRegistry::DATE_FIELDS;

        if (! $creating) {
            $keys = array_values(array_filter($keys, fn (string $field) => array_key_exists($field, $input)));
        }

        $prepared = $input;

        foreach ($keys as $field) {
            if (array_key_exists($field, $prepared) && $prepared[$field] === '') {
                $prepared[$field] = null;
            }
        }

        $data = validator($prepared, array_fill_keys($keys, $rules))->validate();
        $dates = [];

        foreach ($keys as $field) {
            $dates[$field] = $this->optionalDate($data[$field] ?? null);
        }

        return $dates;
    }

    private function seedChecklist(User $actor, CandidateRecord $record): void
    {
        foreach (CandidateRegistry::CHECKLIST as $key => $definition) {
            CandidateChecklistItem::query()->create([
                'tenant_id' => $record->tenant_id,
                'candidate_record_id' => $record->id,
                'item_key' => $key,
                'status' => $definition['initial'],
                'remark' => null,
                'updated_by' => $actor->id,
            ]);
        }
    }

    private function reference(): string
    {
        do {
            $reference = 'CMR-'.strtoupper(Str::random(8));
        } while (CandidateRecord::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return array{path: string, mime: string, size: int}
     */
    private function storePrivateFile(UploadedFile $file, int $tenantId, int $recordId, string $folder): array
    {
        $mime = (string) $file->getMimeType();
        $extension = CandidateForm::MIMES[$mime] ?? null;
        $size = (int) $file->getSize();

        if ($extension === null || $size < 1 || $size > CandidateForm::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF, JPEG, PNG, or WEBP file up to 5 MB.']);
        }

        $path = 'candidate-records/'.$tenantId.'/'.$recordId.'/'.$folder.'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk('local')->put($path, (string) file_get_contents((string) $file->getRealPath()));

        if ($stored !== true) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        return ['path' => $path, 'mime' => $mime, 'size' => $size];
    }

    private function privateDownload(string $path, int $tenantId, int $recordId, string $folder, string $name, string $mime): BinaryFileResponse
    {
        abort_unless(
            CandidateRegistry::ownsPath($path, $tenantId, $recordId, $folder) && Storage::disk('local')->exists($path),
            404,
        );

        $response = response()->download(Storage::disk('local')->path($path), $this->safeDownloadName($name), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function payment(CandidateRecord $record, int $paymentId): CandidatePayment
    {
        $payment = CandidatePayment::query()
            ->where('candidate_record_id', $record->id)
            ->where('tenant_id', $record->tenant_id)
            ->whereKey($paymentId)
            ->first();
        abort_if($payment === null, 404);

        return $payment;
    }

    private function event(User $actor, CandidateRecord $record, string $action, ?string $subject, ?string $from, ?string $to, ?string $note): void
    {
        CandidateRecordEvent::query()->create([
            'tenant_id' => $record->tenant_id,
            'candidate_record_id' => $record->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'subject' => $subject,
            'from_value' => $from,
            'to_value' => $to,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function eventDetail(CandidateRecordEvent $event): string
    {
        $subject = (string) $event->subject;

        return match ($event->action) {
            'registered' => 'Candidate registered',
            'updated' => 'Profile updated',
            'expiry_changed' => $this->dateLabel($subject).': '.($event->from_value ?: 'Not set').' → '.($event->to_value ?: 'Not set'),
            'document_uploaded' => 'Uploaded '.(CandidateRegistry::DOCUMENTS[$subject] ?? 'document'),
            'document_downloaded' => 'Downloaded '.(CandidateRegistry::DOCUMENTS[$subject] ?? 'document'),
            'payment_recorded' => 'Recorded a USD payment',
            'receipt_uploaded' => 'Uploaded a payment receipt',
            'receipt_downloaded' => 'Downloaded a payment receipt',
            'checklist_updated' => (CandidateRegistry::CHECKLIST[$subject]['label'] ?? 'Checklist').': '.($event->from_value ?: '').' → '.($event->to_value ?: '').($event->note ? ' — '.$event->note : ''),
            'revealed' => 'Viewed '.match ($subject) {
                'ssn' => 'SSN',
                'account' => 'account number',
                'routing' => 'routing number',
                'i94' => 'I-94 number',
                'ead_number' => 'EAD number',
                default => 'a protected field',
            },
            'secret_replaced' => 'Replaced '.match ($subject) {
                'ssn' => 'SSN',
                'account' => 'account number',
                'routing' => 'routing number',
                'i94' => 'I-94 number',
                'ead_number' => 'EAD number',
                default => 'a protected field',
            },
            default => 'Record updated',
        };
    }

    private function dateLabel(string $field): string
    {
        return match ($field) {
            'ead_expiry_date' => 'EAD expiry',
            'passport_expiry_date' => 'Passport expiry',
            default => 'Work authorization expiry',
        };
    }

    private function secretInput(string $field): string
    {
        return match ($field) {
            'account' => 'account_number',
            'routing' => 'routing_number',
            'i94' => 'i94_number',
            'ead_number' => 'ead_number',
            default => 'ssn',
        };
    }

    private function identityCode(string $value): ?string
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $value));

        if ($value === '' || strlen($value) > 40 || preg_match('/^[A-Z0-9]+$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function dateString(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value, (string) config('app.timezone'))->toDateString();
    }

    private function optionalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->dateString($value);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private function displayName(UploadedFile $file): string
    {
        $name = trim(str_replace(['\\', '/', "\0", "\r", "\n"], '', (string) $file->getClientOriginalName()));

        return $name === '' ? 'document' : mb_substr($name, 0, 180);
    }

    private function safeDownloadName(string $name): string
    {
        $name = trim(str_replace(['\\', '/', "\0", "\r", "\n"], '', $name));

        return $name === '' ? 'document' : $name;
    }
}
