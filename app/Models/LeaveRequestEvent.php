<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LeaveRequestEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'action',
        'note',
    ];
}
