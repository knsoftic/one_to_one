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
        'user.data_exported' => "Downloaded a user's data",
        'user.session_ended' => 'Signed a user out of a browser',
        'users.exported' => 'Exported the users list',
        'users.bulk' => 'Changed many accounts at once',
        'chat.viewed' => 'Opened a chat',
        'messages.searched' => 'Searched messages',
        'message.deleted' => 'Deleted a message',
        'group.deleted' => 'Deleted a group',
        'channel.deleted' => 'Deleted a channel',
        'community.deleted' => 'Deleted a community',
        'status.deleted' => 'Deleted a status update',
        'report.updated' => 'Reviewed a report',
        'settings.updated' => 'Changed app settings',
        'app.release_updated' => 'Published an app update',
        'app.brand_updated' => 'Changed the app name or icon',
        'backup.created' => 'Made a server backup',
        'backup.downloaded' => 'Downloaded a server backup',
        'backup.deleted' => 'Deleted a server backup',
        'ad.created' => 'Created an ad campaign',
        'ad.updated' => 'Edited an ad campaign',
        'ad.deleted' => 'Deleted an ad campaign',
        // Y2 — paid features
        'plan.created' => 'Created a plan',
        'plan.updated' => 'Edited a plan',
        'plan.deleted' => 'Deleted a plan',
        'coin_pack.created' => 'Created a coin pack',
        'coin_pack.updated' => 'Edited a coin pack',
        'coin_pack.deleted' => 'Deleted a coin pack',
        'payment.approved' => 'Approved a payment',
        'payment.rejected' => 'Rejected a payment',
        'payment.refunded' => 'Refunded a payment',
        'payment.retried' => 'Retried delivering a payment',
        'payment.proof_viewed' => 'Viewed a payment screenshot',
        'promotion.approved' => 'Approved a promotion',
        'promotion.rejected' => 'Rejected a promotion',
        'promotion.stopped' => 'Stopped a promotion',
        'subscription.granted' => 'Granted a plan',
        'subscription.ended' => 'Ended a plan',
        'coins.adjusted' => 'Adjusted coins',
        'wallet.frozen' => 'Froze or unfroze a wallet',
        'badge.granted' => 'Granted a verified badge',
        'badge.removed' => 'Removed a verified badge',
        'referral.voided' => 'Reversed a referral',
        'paid.settings_updated' => 'Changed paid-feature settings',
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
