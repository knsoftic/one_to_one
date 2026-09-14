<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something an administrator did in the admin panel, e.g. opened a chat or banned someone.
 */
class AdminAuditLog extends Model
{
    public const ACTIONS = [
        'user.updated' => 'Edited a user',
        'user.status' => 'Changed account status',
        'user.banned' => 'Banned a user',
        'user.unbanned' => 'Lifted a ban',
        'user.role' => 'Changed a role',
        'user.logged_out' => 'Signed a user out',
        'user.photo_removed' => 'Removed a profile photo',
        'user.two_step_reset' => 'Turned off two-step verification',
        'user.deleted' => 'Deleted a user',
        'chat.viewed' => 'Opened a chat',
        'messages.searched' => 'Searched messages',
        'message.deleted' => 'Deleted a message',
        'group.deleted' => 'Deleted a group',
        'channel.deleted' => 'Deleted a channel',
        'community.deleted' => 'Deleted a community',
        'status.deleted' => 'Deleted a status update',
        'report.updated' => 'Reviewed a report',
        'settings.updated' => 'Changed app settings',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
