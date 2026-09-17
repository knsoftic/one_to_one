<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How many times a house ad was shown to a user in one placement on a day, for frequency capping (Y1).
 */
class AdView extends Model
{
    public $timestamps = false;

    protected $fillable = ['campaign_id', 'user_id', 'placement', 'day', 'views', 'clicked'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'views' => 'integer',
            'clicked' => 'boolean',
        ];
    }
}
