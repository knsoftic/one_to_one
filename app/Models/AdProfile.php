<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ad-targeting profile of a user who turned personalised ads on (Y1). Deleted the moment
 * they turn it off. Holds only broad, non-sensitive signals.
 */
class AdProfile extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'country', 'region', 'timezone', 'locale', 'platform', 'os_version',
        'app_version', 'location_allowed', 'coarse_location', 'gender', 'birth_year', 'interests', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'location_allowed' => 'boolean',
            'birth_year' => 'integer',
            'interests' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function age(): ?int
    {
        return $this->birth_year ? max(0, (int) date('Y') - $this->birth_year) : null;
    }

    /** What the user sees under "The ad data we keep about you". */
    public function summary(): array
    {
        return array_filter([
            'Country' => $this->country,
            'Area' => $this->region,
            'Time zone' => $this->timezone,
            'Language' => $this->locale,
            'Device' => $this->platform ? trim($this->platform.' '.$this->os_version) : null,
            'App version' => $this->app_version,
            'Approximate location' => $this->coarse_location,
            'Gender' => $this->gender,
            'Age' => $this->age(),
            'Interests' => $this->interests ? implode(', ', $this->interests) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
