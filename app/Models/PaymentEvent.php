<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook or callback from a payment provider (Y2), recorded before it is applied. Unique per
 * (gateway, event id); `processed_at` null means it was received but not applied — a retry after
 * a failure picks it up again, a duplicate after success is a no-op.
 */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['gateway', 'event_id', 'type', 'payment_id', 'payload', 'processed_at', 'error', 'created_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
