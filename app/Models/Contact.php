<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $fillable = [
        'user_id',
        'contact_user_id',
        'name',
        'phone',
    ];

    /** The owner of the phone book entry. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The registered user the entry points to. */
    public function contactUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }
}
