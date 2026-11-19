<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateRecord extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    /**
     * Ciphertext must not appear if a record is serialized into a page, log, or debug payload.
     *
     * @var list<string>
     */
    protected $hidden = [
        'ssn_ciphertext',
        'account_ciphertext',
        'routing_ciphertext',
        'i94_ciphertext',
        'ead_number_ciphertext',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'start_date' => 'date',
            'work_authorization_expires_on' => 'date',
            'ead_expiry_date' => 'date',
            'passport_expiry_date' => 'date',
            'hourly_rate' => 'decimal:2',
            'stated_amount' => 'decimal:2',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CandidateRecordDocument::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CandidatePayment::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(CandidateChecklistItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CandidateRecordEvent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
