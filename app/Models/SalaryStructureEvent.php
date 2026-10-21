<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SalaryStructureEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;
}
