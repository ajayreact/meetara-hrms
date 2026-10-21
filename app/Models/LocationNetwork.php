<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationNetwork extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'cidr',
    ];

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'location_id');
    }
}
