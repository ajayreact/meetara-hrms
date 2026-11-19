<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandaloneIdCard extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['draft', 'active', 'reissued', 'inactive'];

    public const BLOOD_GROUPS = EmployeeIdCard::BLOOD_GROUPS;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'issued_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function companyProfile(): BelongsTo
    {
        return $this->belongsTo(IdCardCompanyProfile::class, 'company_profile_id');
    }

    public function reissuedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissued_from_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(StandaloneIdCardImportBatch::class, 'import_batch_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(StandaloneIdCardIssue::class, 'id_card_id');
    }
}
