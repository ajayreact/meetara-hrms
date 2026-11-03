<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentCatalog
{
    /**
     * @var array<string, string>
     */
    public const GROUPS = [
        'identity' => 'Identity Documents',
        'education' => 'Education Documents',
        'employment' => 'Employment Documents',
        'payroll' => 'Bank and Payroll Documents',
        'other' => 'Other Documents',
    ];

    /**
     * Built-in categories. Keys already used by onboarding stay unchanged.
     *
     * @var list<array{key: string, name: string, group: string, required: bool, active: bool}>
     */
    public const DEFAULTS = [
        ['key' => 'aadhaar', 'name' => 'Aadhaar Card', 'group' => 'identity', 'required' => true, 'active' => true],
        ['key' => 'pan', 'name' => 'PAN Card', 'group' => 'identity', 'required' => true, 'active' => true],
        ['key' => 'passport', 'name' => 'Passport', 'group' => 'identity', 'required' => false, 'active' => true],
        ['key' => 'driving_licence', 'name' => 'Driving Licence', 'group' => 'identity', 'required' => false, 'active' => true],
        ['key' => 'voter_id', 'name' => 'Voter ID', 'group' => 'identity', 'required' => false, 'active' => true],
        ['key' => 'tenth_certificate', 'name' => '10th Certificate', 'group' => 'education', 'required' => false, 'active' => true],
        ['key' => 'twelfth_certificate', 'name' => '12th Certificate', 'group' => 'education', 'required' => false, 'active' => true],
        ['key' => 'diploma_certificate', 'name' => 'Diploma Certificate', 'group' => 'education', 'required' => false, 'active' => true],
        ['key' => 'degree_certificate', 'name' => 'Degree Certificate', 'group' => 'education', 'required' => false, 'active' => true],
        ['key' => 'postgraduate_certificate', 'name' => 'Postgraduate Certificate', 'group' => 'education', 'required' => false, 'active' => true],
        ['key' => 'other_education', 'name' => 'Other Education Documents', 'group' => 'education', 'required' => false, 'active' => true],
        ['key' => 'experience_letter', 'name' => 'Previous Experience Letter', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'relieving_letter', 'name' => 'Relieving Letter', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'previous_payslip', 'name' => 'Previous Employment Payslip', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'appointment_letter', 'name' => 'Appointment Letter', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'employment_agreement', 'name' => 'Employment Agreement', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'other_employment', 'name' => 'Other Employment Documents', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'signed_offer', 'name' => 'Signed offer letter', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'resume', 'name' => 'Resume', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'generated_offer', 'name' => 'Generated offer letter', 'group' => 'employment', 'required' => false, 'active' => true],
        ['key' => 'bank_proof', 'name' => 'Bank Proof', 'group' => 'payroll', 'required' => true, 'active' => true],
        ['key' => 'cancelled_cheque', 'name' => 'Cancelled Cheque', 'group' => 'payroll', 'required' => false, 'active' => true],
        ['key' => 'other_payroll', 'name' => 'Other Payroll Documents', 'group' => 'payroll', 'required' => false, 'active' => true],
        ['key' => 'address_proof', 'name' => 'Address Proof', 'group' => 'other', 'required' => false, 'active' => true],
        ['key' => 'photograph', 'name' => 'Photograph', 'group' => 'other', 'required' => true, 'active' => true],
        ['key' => 'other_supporting', 'name' => 'Other Supporting Documents', 'group' => 'other', 'required' => false, 'active' => true],
        ['key' => 'other', 'name' => 'Other', 'group' => 'other', 'required' => false, 'active' => true],
    ];

    public function ensure(int $companyId): void
    {
        if ($companyId <= 0 || ! Schema::hasTable('document_category_settings')) {
            return;
        }

        $existing = DB::table('document_category_settings')->where('tenant_id', $companyId)->pluck('category_key')->all();
        $now = now();
        $rows = [];

        foreach (self::DEFAULTS as $index => $category) {
            if (in_array($category['key'], $existing, true)) {
                continue;
            }

            $rows[] = [
                'tenant_id' => $companyId,
                'category_key' => $category['key'],
                'name' => $category['name'],
                'group_key' => $category['group'],
                'is_required' => $category['required'],
                'is_active' => $category['active'],
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('document_category_settings')->insert($rows);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(int $companyId): array
    {
        $this->ensure($companyId);

        return DB::table('document_category_settings')
            ->where('tenant_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function uploadChoices(int $companyId, bool $manager): array
    {
        return array_values(array_filter($this->all($companyId), function (array $category) use ($manager): bool {
            if (! $category['active'] || $category['key'] === 'generated_offer') {
                return false;
            }

            return $manager || $category['key'] !== 'generated_offer';
        }));
    }

    public function name(int $companyId, string $key): string
    {
        $this->ensure($companyId);
        $name = DB::table('document_category_settings')
            ->where('tenant_id', $companyId)
            ->where('category_key', $key)
            ->value('name');

        return is_string($name) && $name !== '' ? $name : EmployeeDocuments::label($key);
    }

    public function allows(int $companyId, string $key, bool $manager): bool
    {
        if ($key === 'generated_offer') {
            return $manager;
        }

        $this->ensure($companyId);
        $row = DB::table('document_category_settings')
            ->where('tenant_id', $companyId)
            ->where('category_key', $key)
            ->first();

        if ($row === null) {
            return array_key_exists($key, EmployeeDocuments::CATEGORIES);
        }

        return (bool) $row->is_active || array_key_exists($key, EmployeeDocuments::CATEGORIES);
    }

    /**
     * @param  list<array<string, mixed>>  $settings
     */
    public function save(User $actor, array $settings): void
    {
        $companyId = (int) $actor->companyId();
        abort_unless($this->manages($actor), 403);
        $this->ensure($companyId);
        $known = DB::table('document_category_settings')->where('tenant_id', $companyId)->pluck('category_key')->all();

        DB::transaction(function () use ($companyId, $settings, $known): void {
            foreach ($settings as $setting) {
                $key = (string) ($setting['key'] ?? '');
                if (! in_array($key, $known, true)) {
                    throw ValidationException::withMessages(['categories' => 'Choose a document category that already exists.']);
                }

                DB::table('document_category_settings')
                    ->where('tenant_id', $companyId)
                    ->where('category_key', $key)
                    ->update([
                        'name' => $this->cleanName((string) ($setting['name'] ?? '')),
                        'is_required' => (bool) ($setting['required'] ?? false),
                        'is_active' => (bool) ($setting['active'] ?? true),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function add(User $actor, string $name, string $group): void
    {
        $companyId = (int) $actor->companyId();
        abort_unless($this->manages($actor), 403);
        abort_unless(array_key_exists($group, self::GROUPS), 422);
        $this->ensure($companyId);
        $key = $this->key($name);
        $exists = DB::table('document_category_settings')->where('tenant_id', $companyId)->where('category_key', $key)->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A category with this name already exists.']);
        }

        $sort = (int) DB::table('document_category_settings')->where('tenant_id', $companyId)->max('sort_order');
        DB::table('document_category_settings')->insert([
            'tenant_id' => $companyId,
            'category_key' => $key,
            'name' => $this->cleanName($name),
            'group_key' => $group,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => $sort + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function manages(User $actor): bool
    {
        return $actor->companyId() !== null && in_array($actor->applicationRole(), ['company_admin', 'hr'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        return [
            'key' => (string) $row->category_key,
            'name' => (string) $row->name,
            'group' => self::GROUPS[$row->group_key] ?? (string) $row->group_key,
            'groupKey' => (string) $row->group_key,
            'required' => (bool) $row->is_required,
            'active' => (bool) $row->is_active,
        ];
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if ($name === '' || strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Enter a category name of 80 characters or fewer.']);
        }

        return $name;
    }

    private function key(string $name): string
    {
        $key = Str::snake(Str::limit($this->cleanName($name), 40, ''));
        $key = substr(preg_replace('/[^a-z0-9_]/', '', $key) ?? '', 0, 40);

        if ($key === '' || preg_match('/^[a-z]/', $key) !== 1) {
            throw ValidationException::withMessages(['name' => 'Use a category name that starts with a letter.']);
        }

        return $key;
    }
}
