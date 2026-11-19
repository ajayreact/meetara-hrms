<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateChecklistItem extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    public function record(): BelongsTo
    {
        return $this->belongsTo(CandidateRecord::class, 'candidate_record_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
