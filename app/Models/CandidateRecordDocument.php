<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateRecordDocument extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = [
        'storage_path',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(CandidateRecord::class, 'candidate_record_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
