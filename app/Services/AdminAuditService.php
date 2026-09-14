<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records what administrators do in the admin panel — every chat they open, every
 * ban and deletion — so access to people's messages is always traceable.
 */
class AdminAuditService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(User $admin, string $action, ?Model $target, string $description, array $meta = []): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'admin_id' => $admin->getKey(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'meta' => $meta ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
