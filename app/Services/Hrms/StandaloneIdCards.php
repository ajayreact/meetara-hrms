<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\IdCardCompanyProfile;
use App\Models\StandaloneIdCard;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StandaloneIdCards
{
    public function __construct(private readonly StandaloneIdCardIssuer $issuer) {}

    /**
     * @return array{
     *     cards: list<array<string, mixed>>,
     *     selectedCardId: int|null,
     *     card: array<string, mixed>,
     *     countries: list<array{value: string, label: string}>,
     *     bloodGroups: list<string>,
     *     issue: array<string, mixed>,
     *     query: string
     * }
     */
    public function page(User $actor, ?int $cardId = null, string $query = ''): array
    {
        $context = $this->context($actor);
        $cards = $this->search($context['tenantId'], $query);
        $row = null;
        $card = $this->emptyCard();

        if ($cardId !== null) {
            $row = $this->ownedOpenCard($context['tenantId'], $cardId);
            $card = $this->presentCard($context['tenantId'], $row);
        }

        return [
            'cards' => $cards,
            'selectedCardId' => $row !== null ? (int) $row->id : null,
            'card' => $card,
            'countries' => [
                ['value' => 'india', 'label' => 'India'],
                ['value' => 'usa', 'label' => 'USA'],
            ],
            'bloodGroups' => StandaloneIdCard::BLOOD_GROUPS,
            'issue' => $this->issuer->presentIssueState($context['tenantId'], $row),
            'query' => $query,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{message: string, cardId: int, card: array<string, mixed>}
     */
    public function save(User $actor, array $input, ?UploadedFile $photo = null, ?StandaloneIdCard $existing = null): array
    {
        $context = $this->context($actor);

        if (! Schema::hasTable('standalone_id_cards')) {
            throw ValidationException::withMessages(['card' => 'Standalone ID cards are not available yet.']);
        }

        $country = $this->country((string) ($input['country'] ?? 'india'));
        $profileId = IdCardCompanyProfile::query()
            ->inCompany($context['tenantId'])
            ->where('country', $country)
            ->value('id');

        $fullName = $this->requiredString($input['full_name'] ?? null, 'full_name', 'Enter the full name.');
        $cardNumber = $this->requiredString($input['card_number'] ?? null, 'card_number', 'Enter a unique card number.');

        try {
            return DB::transaction(function () use ($context, $country, $profileId, $input, $photo, $existing, $fullName, $cardNumber): array {
                $row = $existing;

                if ($row !== null) {
                    $row = $this->ownedOpenCard($context['tenantId'], (int) $row->id);
                }

                // Serialize card-number checks with the write so concurrent creates cannot both pass.
                StandaloneIdCard::query()
                    ->inCompany($context['tenantId'])
                    ->where('card_number', $cardNumber)
                    ->lockForUpdate()
                    ->get();
                $this->assertUniqueCardNumber($context['tenantId'], $cardNumber, $row?->id);

                $photoPath = $row?->photo_path;
                $photoMime = $row?->photo_mime;

                if ($photo !== null) {
                    [$photoPath, $photoMime] = $this->storePhoto(
                        $context['tenantId'],
                        $photo,
                        is_string($photoPath) ? $photoPath : null,
                        $row?->id !== null ? (int) $row->id : null,
                    );
                }

                $payload = [
                    'country' => $country,
                    'company_profile_id' => $profileId !== null ? (int) $profileId : null,
                    'full_name' => $fullName,
                    'card_number' => $cardNumber,
                    'designation' => $this->nullableString($input['designation'] ?? null),
                    'department' => $this->nullableString($input['department'] ?? null),
                    'email' => $this->nullableString($input['email'] ?? null),
                    'phone' => $this->nullableString($input['phone'] ?? null),
                    'date_of_birth' => $this->dateOfBirth($input['date_of_birth'] ?? null),
                    'blood_group' => $this->nullableString($input['blood_group'] ?? null),
                    'emergency_contact_number' => $this->nullableString($input['emergency_contact_number'] ?? null),
                    'photo_path' => $photoPath,
                    'photo_mime' => $photoMime,
                    'updated_by' => $context['userId'],
                    'updated_at' => now(),
                ];

                if ($row === null) {
                    $payload['tenant_id'] = $context['tenantId'];
                    $payload['status'] = 'draft';
                    $payload['qr_token'] = $this->issuer->freshQrToken();
                    $payload['created_by'] = $context['userId'];
                    $payload['created_at'] = now();
                    $id = DB::table('standalone_id_cards')->insertGetId($payload);
                    $row = StandaloneIdCard::query()->inCompany($context['tenantId'])->whereKey($id)->firstOrFail();
                    $message = 'Standalone ID card created.';
                } else {
                    DB::table('standalone_id_cards')
                        ->where('tenant_id', $context['tenantId'])
                        ->where('id', $row->id)
                        ->update($payload);
                    $row = $row->fresh();
                    $message = 'Standalone ID card updated.';
                }

                return [
                    'message' => $message,
                    'cardId' => (int) $row->id,
                    'card' => $this->presentCard($context['tenantId'], $row),
                ];
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueCardNumberViolation($exception)) {
                throw ValidationException::withMessages([
                    'card_number' => 'This card number is already used for another standalone ID card.',
                ]);
            }

            throw $exception;
        }
    }

    public function photoResponse(User $actor, StandaloneIdCard $card): StreamedResponse
    {
        $context = $this->context($actor);
        abort_unless((int) $card->tenant_id === $context['tenantId'], 404);
        abort_unless($this->privatePhoto($context['tenantId'], $card->photo_path), 404);

        return Storage::disk('local')->response((string) $card->photo_path, basename((string) $card->photo_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => (string) ($card->photo_mime ?: 'application/octet-stream'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(int $tenantId, string $query): array
    {
        $builder = StandaloneIdCard::query()
            ->inCompany($tenantId)
            ->whereIn('status', ['draft', 'active'])
            ->orderByDesc('id');

        $needle = trim($query);
        if ($needle !== '') {
            $like = '%'.$needle.'%';
            $builder->where(function ($q) use ($like): void {
                $q->where('full_name', 'like', $like)
                    ->orWhere('card_number', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('department', 'like', $like)
                    ->orWhere('designation', 'like', $like);
            });
        }

        return $builder
            ->limit(50)
            ->get()
            ->map(fn (StandaloneIdCard $card): array => $this->presentCard($tenantId, $card))
            ->values()
            ->all();
    }

    private function ownedOpenCard(int $tenantId, int $cardId): StandaloneIdCard
    {
        $card = StandaloneIdCard::query()
            ->inCompany($tenantId)
            ->whereKey($cardId)
            ->whereIn('status', ['draft', 'active'])
            ->first();

        abort_unless($card !== null, 404);

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCard(int $tenantId, ?StandaloneIdCard $row): array
    {
        if ($row === null) {
            return $this->emptyCard();
        }

        $hasPhoto = $this->privatePhoto($tenantId, $row->photo_path);

        return [
            'id' => (int) $row->id,
            'fullName' => (string) $row->full_name,
            'cardNumber' => (string) $row->card_number,
            'designation' => (string) ($row->designation ?? ''),
            'department' => (string) ($row->department ?? ''),
            'email' => (string) ($row->email ?? ''),
            'phone' => (string) ($row->phone ?? ''),
            'dateOfBirth' => $row->date_of_birth instanceof \DateTimeInterface
                ? $row->date_of_birth->format('Y-m-d')
                : trim((string) ($row->date_of_birth ?? '')),
            'country' => (string) $row->country,
            'bloodGroup' => (string) ($row->blood_group ?? ''),
            'emergencyContactNumber' => (string) ($row->emergency_contact_number ?? ''),
            'hasPhoto' => $hasPhoto,
            'photoUrl' => $hasPhoto ? '/id-cards/standalone/'.$row->id.'/photo' : null,
            'status' => (string) $row->status,
            'updatedAt' => $row->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCard(): array
    {
        return [
            'id' => null,
            'fullName' => '',
            'cardNumber' => '',
            'designation' => '',
            'department' => '',
            'email' => '',
            'phone' => '',
            'dateOfBirth' => '',
            'country' => 'india',
            'bloodGroup' => '',
            'emergencyContactNumber' => '',
            'hasPhoto' => false,
            'photoUrl' => null,
            'status' => 'draft',
            'updatedAt' => null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storePhoto(int $tenantId, UploadedFile $photo, ?string $previous, ?int $cardId = null): array
    {
        $extension = strtolower((string) $photo->getClientOriginalExtension());
        $mime = (string) ($photo->getMimeType() ?: '');
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

        if (! array_key_exists($extension, $allowed) || ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages([
                'photo' => 'Upload a PNG or JPEG photo up to 2 MB.',
            ]);
        }

        $contents = file_get_contents($photo->getRealPath());

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['photo' => 'The photo could not be read.']);
        }

        $path = 'id-cards/'.$tenantId.'/standalone/photos/'.Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        if (is_string($previous) && $previous !== '' && $previous !== $path && $this->privatePhoto($tenantId, $previous)) {
            $stillUsed = DB::table('standalone_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('photo_path', $previous)
                ->when($cardId !== null, fn ($query) => $query->where('id', '!=', $cardId))
                ->exists();

            if (! $stillUsed) {
                Storage::disk('local')->delete($previous);
            }
        }

        return [$path, $allowed[$extension]];
    }

    private function privatePhoto(int $tenantId, mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/standalone/photos/')) {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }

    private function assertUniqueCardNumber(int $tenantId, string $cardNumber, ?int $exceptId): void
    {
        $exists = DB::table('standalone_id_cards')
            ->where('tenant_id', $tenantId)
            ->where('card_number', $cardNumber)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'card_number' => 'This card number is already used for another standalone ID card.',
            ]);
        }
    }

    private function isUniqueCardNumberViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'standalone_id_cards_tenant_card_number_unique')
            || (str_contains($message, 'unique') && str_contains($message, 'card_number'));
    }

    private function dateOfBirth(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages(['date_of_birth' => 'Date of birth is required.']);
        }

        try {
            $date = Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date_of_birth' => 'Enter a valid date of birth.']);
        }

        if ($date->year < 1900 || $date->isFuture()) {
            throw ValidationException::withMessages(['date_of_birth' => 'Enter a valid date of birth.']);
        }

        return $date->toDateString();
    }

    /**
     * @return array{tenantId: int, userId: int}
     */
    private function context(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['idCardsManage'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        if (! Schema::hasTable('standalone_id_cards')) {
            abort(404);
        }

        return [
            'tenantId' => (int) $actor->companyId(),
            'userId' => (int) $actor->id,
        ];
    }

    private function country(string $country): string
    {
        $value = strtolower(trim($country));

        if (! in_array($value, IdCardCompanyProfile::COUNTRIES, true)) {
            return 'india';
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field, string $message): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([$field => $message]);
        }

        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
