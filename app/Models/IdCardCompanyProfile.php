<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdCardCompanyProfile extends Model
{
    use BelongsToCompany;

    public const COUNTRIES = ['india', 'usa'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(EmployeeIdCard::class, 'company_profile_id');
    }
}
