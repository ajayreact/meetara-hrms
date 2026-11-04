<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyOnboarding
{
    /** @var list<string> */
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Company
    {
        $this->authorize($actor);

        try {
            return DB::transaction(function () use ($actor, $data): Company {
                $company = new Company;
                $company->forceFill([
                    'name' => $this->name($data),
                    'legal_name' => $this->nullableText($data['legal_name'] ?? null),
                    'slug' => $this->slug((string) $data['slug']),
                    'timezone' => $this->timezone((string) $data['timezone']),
                    'status' => $this->status($data['status'] ?? 'active'),
                    'week_off' => $this->weekOff($data['week_off'] ?? ['saturday', 'sunday']),
                    'email' => $this->nullableText($data['email'] ?? null),
                    'phone' => $this->nullableText($data['phone'] ?? null),
                    'address' => $this->nullableText($data['address'] ?? null),
                ]);
                $company->save();
                app(RoleAssignment::class)->ensureStandard((int) $company->id);
                $this->provisionAdmin($actor, (int) $company->id, $data);
                $this->record($actor, (int) $company->id, 'created');

                return $company;
            });
        } catch (QueryException $exception) {
            $this->reraiseDuplicateSlug($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, int $companyId, array $data): Company
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $companyId, $data): Company {
            $company = $this->lock($companyId);
            $company->forceFill([
                'name' => $this->name($data),
                'legal_name' => $this->nullableText($data['legal_name'] ?? null),
                'timezone' => $this->timezone((string) $data['timezone']),
                'week_off' => $this->weekOff($data['week_off'] ?? []),
                'email' => $this->nullableText($data['email'] ?? null),
                'phone' => $this->nullableText($data['phone'] ?? null),
                'address' => $this->nullableText($data['address'] ?? null),
            ]);
            $company->save();
            $this->record($actor, (int) $company->id, 'updated');

            return $company;
        });
    }

    public function transition(User $actor, int $companyId, string $status): Company
    {
        $this->authorize($actor);
        abort_unless(in_array($status, ['active', 'inactive'], true), 422);

        return DB::transaction(function () use ($actor, $companyId, $status): Company {
            $company = $this->lock($companyId);

            if ((string) $company->status === $status) {
                return $company;
            }

            $company->forceFill(['status' => $status])->save();

            if ($status === 'inactive') {
                $this->endCompanySessions($companyId);
            }

            $this->record($actor, $companyId, $status === 'active' ? 'reactivated' : 'deactivated');

            return $company;
        });
    }

    public static function normalizeSlug(string $value): string
    {
        return Str::slug($value);
    }

    protected function record(User $actor, int $companyId, string $action): void
    {
        if (! Schema::hasTable('company_lifecycle_events')) {
            return;
        }

        DB::table('company_lifecycle_events')->insert([
            'tenant_id' => $companyId,
            'actor_id' => $actor->id,
            'action' => $action,
            'created_at' => now(),
        ]);
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isPlatformAdmin(), 403);
    }

    private function lock(int $companyId): Company
    {
        $company = Company::query()->whereKey($companyId)->lockForUpdate()->first();
        abort_if($company === null, 404);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private function provisionAdmin(User $actor, int $companyId, array $data): void
    {
        $email = trim((string) ($data['admin_email'] ?? ''));
        if ($email === '') {
            return;
        }

        app(CompanyAdminInvitation::class)->provisionOwner(
            $actor,
            $companyId,
            trim((string) ($data['admin_name'] ?? '')),
            $email,
            (string) ($data['admin_password'] ?? ''),
        );
    }

    private function status(mixed $value): string
    {
        $status = trim((string) $value);
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose Active or Inactive.',
            ]);
        }

        return $status;
    }

    private function name(array $data): string
    {
        return trim((string) $data['name']);
    }

    private function slug(string $value): string
    {
        $slug = self::normalizeSlug($value);
        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => 'Enter a slug that contains letters or numbers.',
            ]);
        }

        return $slug;
    }

    private function timezone(string $value): string
    {
        if (! in_array($value, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages([
                'timezone' => 'Choose a supported time zone.',
            ]);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function weekOff(mixed $days): array
    {
        if (! is_array($days)) {
            return ['saturday', 'sunday'];
        }

        $selected = array_values(array_unique(array_map('strval', $days)));
        foreach ($selected as $day) {
            if (! in_array($day, self::DAYS, true)) {
                throw ValidationException::withMessages([
                    'week_off' => 'Choose week-off days from the company calendar.',
                ]);
            }
        }

        return $selected;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function endCompanySessions(int $companyId): void
    {
        if (! Schema::hasTable('sessions') || ! Schema::hasColumn('sessions', 'user_id') || ! Schema::hasTable('users')) {
            return;
        }

        $users = DB::table('users')->where('tenant_id', $companyId);
        if (Schema::hasColumn('users', 'is_platform_admin')) {
            $users->where('is_platform_admin', false);
        }

        DB::table('sessions')->whereIn('user_id', $users->pluck('id')->all())->delete();
    }

    private function reraiseDuplicateSlug(QueryException $exception): never
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'slug') || str_contains($message, 'UNIQUE')) {
            throw ValidationException::withMessages([
                'slug' => 'This slug is already used by a company.',
            ]);
        }

        throw $exception;
    }
}
