<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    use BelongsToCompany;

    protected $table = 'locations';

    protected $fillable = [
        'name',
        'address',
        'timezone',
        'status',
        'latitude',
        'longitude',
        'verification_radius_meters',
        'gps_verification_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'verification_radius_meters' => 'integer',
            'gps_verification_enabled' => 'boolean',
        ];
    }

    public function networks(): HasMany
    {
        return $this->hasMany(LocationNetwork::class, 'location_id');
    }
}
