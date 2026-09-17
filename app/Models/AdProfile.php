<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What we use to choose someone's ads (Y1): their own country, the device the app runs on, how
 * often they open it, and — only while the phone's location permission is granted — a rounded
 * location. Gender and age come from the person's own profile, not from here.
 */
class AdProfile extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'country', 'region', 'city', 'timezone', 'locale', 'platform', 'os_version',
        'device_model', 'app_version', 'ip', 'ip_country', 'location_allowed', 'coarse_location',
        'location_at', 'opens', 'last_open_at', 'segments', 'updated_at',
    ];

    protected $hidden = ['ip'];

    protected function casts(): array
    {
        return [
            'location_allowed' => 'boolean',
            'opens' => 'integer',
            'segments' => 'array',
            'location_at' => 'datetime',
            'last_open_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What the person sees under "What we use to choose your ads". */
    public function summary(?User $user = null): array
    {
        return array_filter([
            'Country' => $this->ip_country ?? $this->country,
            'Area' => $this->city ?? $this->region,
            'Time zone' => $this->timezone,
            'Language' => $this->locale,
            'Device' => trim(($this->device_model ?? $this->platform ?? '').' '.($this->os_version ?? '')) ?: null,
            'App version' => $this->app_version,
            'Approximate location' => $this->coarse_location,
            'Gender' => $user?->gender,
            'Age' => $user?->age(),
            'Interests' => $this->segments ? implode(', ', $this->segments) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
