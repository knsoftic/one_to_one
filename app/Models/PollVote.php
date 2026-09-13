<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PollVote extends Model
{
    public const UPDATED_AT = null;

    public const MAX_OPTIONS = 12;

    protected $fillable = ['message_id', 'user_id', 'option', 'created_at'];

    protected function casts(): array
    {
        return ['option' => 'integer'];
    }
}
