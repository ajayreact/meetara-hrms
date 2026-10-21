<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'pending',
        'used',
    ];
}
