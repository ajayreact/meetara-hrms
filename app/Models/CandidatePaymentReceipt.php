<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidatePaymentReceipt extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = [
        'storage_path',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CandidatePayment::class, 'candidate_payment_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
