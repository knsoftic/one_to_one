<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AppReleaseController;
use App\Http\Controllers\Admin\BackupController as AdminBackupController;
use App\Http\Controllers\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Admin\SpaceController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserDataController;
use App\Http\Controllers\Admin\UserModerationController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppShellController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PhoneLoginController;
use App\Http\Controllers\Auth\TwoStepController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CallLinkController;
use App\Http\Controllers\CallLogController;
use App\Http\Controllers\CallRoomController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatExportController;
use App\Http\Controllers\ChatListController;
use App\Http\Controllers\ChatLockController;
use App\Http\Controllers\ChatPreferencesController;
use App\Http\Controllers\ChatSettingsController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\ContactCardController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DisappearingMessageController;
use App\Http\Controllers\GifController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LinkedDeviceController;
use App\Http\Controllers\LinkPreviewController;
use App\Http\Controllers\LiveLocationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessagePinController;
use App\Http\Controllers\MessageReactionController;
use App\Http\Controllers\MessageStatusController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PhoneChangeController;
use App\Http\Controllers\PollVoteController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileQrController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StarredMessageController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\StickerController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ViewOnceController;
use App\Http\Controllers\WebPushController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/

// Privacy policy, terms, child safety standards and account deletion (X5, Google Play).
Route::get('/{page}', [LegalController::class, 'show'])->whereIn('page', array_keys(LegalController::PAGES))->name('legal');

// Installable app (X3) and the Android app download (X4).
Route::get('/manifest.webmanifest', [AppShellController::class, 'manifest'])->name('manifest');
Route::get('/download/android', [AppShellController::class, 'downloadAndroid'])->middleware('throttle:60,1')->name('app.download.android');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('register.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    // Log in with the mobile number and an SMS code (A1)
    Route::get('/login/phone', [PhoneLoginController::class, 'show'])->name('login.phone');
    Route::post('/login/phone', [PhoneLoginController::class, 'send'])->middleware('throttle:otp')->name('login.phone.send');
    Route::get('/login/phone/code', [PhoneLoginController::class, 'code'])->name('login.phone.code');
    Route::post('/login/phone/code', [PhoneLoginController::class, 'verify'])->middleware('throttle:chat-lock')->name('login.phone.verify');
    Route::post('/login/phone/resend', [PhoneLoginController::class, 'resend'])->middleware('throttle:otp')->name('login.phone.resend');

    // Log in by scanning a QR code with a signed-in phone (P10)
    Route::post('/login/qr', [LinkedDeviceController::class, 'create'])->middleware('throttle:chat-actions')->name('login.qr');
    Route::post('/login/qr/{token}/status', [LinkedDeviceController::class, 'status'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:chat-sync')->name('login.qr.status');

    // Two-step verification step of signing in (P7)
    Route::get('/login/verify', [TwoStepController::class, 'show'])->name('two-step.challenge');
    Route::post('/login/verify', [TwoStepController::class, 'verify'])->middleware('throttle:chat-lock')->name('two-step.verify');
    Route::post('/login/verify/forgot', [TwoStepController::class, 'forgot'])->middleware('throttle:password-reset')->name('two-step.forgot');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset')
        ->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Ban screen (admin panel bans): shown right after a banned person is signed out.
Route::get('/account/banned', [AuthController::class, 'banned'])->name('account.banned');

// "Forgot PIN?" email link: turns two-step verification off (P7).
Route::get('/two-step/reset/{user}', [TwoStepController::class, 'reset'])->whereNumber('user')->middleware('signed')->name('two-step.reset');

Route::middleware(['auth', 'active'])->group(function () {
    Route::redirect('/', '/chat')->name('home');

    // Optional profile photo step right after registration
    Route::get('/welcome/photo', [OnboardingController::class, 'photo'])->name('onboarding.photo');
    Route::post('/welcome/photo', [OnboardingController::class, 'savePhoto'])->name('onboarding.photo.store');

    // Phone-book contacts registered on the app
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts/sync', [ContactController::class, 'sync'])
        ->middleware('throttle:contacts-sync')
        ->name('contacts.sync');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])
        ->whereNumber('contact')
        ->name('contacts.destroy');

    // Browser push notifications (X3)
    Route::post('/push/subscriptions', [WebPushController::class, 'store'])->middleware('throttle:chat-actions')->name('web-push.store');
    Route::delete('/push/subscriptions', [WebPushController::class, 'destroy'])->middleware('throttle:chat-actions')->name('web-push.destroy');

    // Mobile app push notification tokens
    Route::post('/devices', [DeviceController::class, 'store'])
        ->middleware('throttle:chat-actions')
        ->name('devices.store');
    Route::delete('/devices', [DeviceController::class, 'destroy'])
        ->middleware('throttle:chat-actions')
        ->name('devices.destroy');

    // Chat dashboard (HTML shell; data is loaded via AJAX)
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{conversation}', [ChatController::class, 'show'])->whereNumber('conversation')->name('chat.show');

    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('/conversations', [ConversationController::class, 'store'])
        ->middleware('throttle:chat-actions')
        ->name('conversations.store');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
        ->whereNumber('conversation')
        ->name('conversations.show');

    // My chat lists ("Family", "Work"…) — C6
    // Chat lock (C9)
    Route::post('/chat-lock/pin', [ChatLockController::class, 'storePin'])->middleware('throttle:chat-lock')->name('chat-lock.pin.store');
    Route::delete('/chat-lock/pin', [ChatLockController::class, 'destroyPin'])->middleware('throttle:chat-lock')->name('chat-lock.pin.destroy');
    Route::post('/chat-lock/unlock', [ChatLockController::class, 'unlock'])->middleware('throttle:chat-lock')->name('chat-lock.unlock');
    Route::post('/chat-lock/lock', [ChatLockController::class, 'lock'])->name('chat-lock.lock');

    Route::get('/chat-lists', [ChatListController::class, 'index'])->name('chat-lists.index');
    Route::post('/chat-lists', [ChatListController::class, 'store'])->middleware('throttle:chat-actions')->name('chat-lists.store');
    Route::patch('/chat-lists/{chatList}', [ChatListController::class, 'update'])->whereNumber('chatList')->middleware('throttle:chat-actions')->name('chat-lists.update');
    Route::delete('/chat-lists/{chatList}', [ChatListController::class, 'destroy'])->whereNumber('chatList')->middleware('throttle:chat-actions')->name('chat-lists.destroy');
    // My chat list settings (Phase 2): pin, mute, archive, unread, favourite, clear, delete
    Route::patch('/conversations/{conversation}/settings', [ChatSettingsController::class, 'update'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-actions')
        ->name('conversations.settings');
    // Export chat (D7)
    Route::get('/conversations/{conversation}/export', [ChatExportController::class, 'download'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-export')
        ->name('conversations.export');
    // Wallpaper of one chat (D2)
    Route::post('/conversations/{conversation}/wallpaper', [ChatPreferencesController::class, 'updateChatWallpaper'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-actions')
        ->name('conversations.wallpaper.update');
    Route::get('/conversations/{conversation}/wallpaper', [ChatPreferencesController::class, 'showChatWallpaper'])
        ->whereNumber('conversation')
        ->name('conversations.wallpaper');
    Route::post('/conversations/{conversation}/clear', [ChatSettingsController::class, 'clear'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-actions')
        ->name('conversations.clear');
    Route::delete('/conversations/{conversation}', [ChatSettingsController::class, 'destroy'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-actions')
        ->name('conversations.destroy');

    // Messages
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])
        ->whereNumber('conversation')
        ->name('messages.index');
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-send')
        ->name('messages.store');

    // Message actions & private attachments
    Route::patch('/messages/{message}', [MessageController::class, 'update'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.update');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.destroy');
    // Media, links and docs of a chat (D1)
    Route::get('/conversations/{conversation}/gallery', [MessageController::class, 'gallery'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-search')
        ->name('conversations.gallery');
    Route::get('/conversations/{conversation}/messages/search', [MessageController::class, 'search'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-search')
        ->name('messages.search');
    Route::post('/messages/{message}/forward', [MessageController::class, 'forward'])
        ->whereNumber('message')
        ->middleware('throttle:chat-send')
        ->name('messages.forward');
    Route::put('/messages/{message}/reaction', [MessageReactionController::class, 'update'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.reaction.update');
    Route::delete('/messages/{message}/reaction', [MessageReactionController::class, 'destroy'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.reaction.destroy');
    Route::put('/messages/{message}/pin', [MessagePinController::class, 'store'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.pin');
    Route::delete('/messages/{message}/pin', [MessagePinController::class, 'destroy'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.unpin');
    Route::get('/starred', [StarredMessageController::class, 'index'])->name('starred.index');
    Route::put('/messages/{message}/star', [StarredMessageController::class, 'store'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.star');
    Route::delete('/messages/{message}/star', [StarredMessageController::class, 'destroy'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.unstar');
    Route::get('/messages/{message}/attachment', [AttachmentController::class, 'show'])
        ->whereNumber('message')
        ->name('messages.attachment');
    // View once (M22)
    Route::post('/messages/{message}/view-once', [ViewOnceController::class, 'open'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.view-once');
    Route::get('/messages/{message}/view-once/file', [ViewOnceController::class, 'file'])
        ->whereNumber('message')
        ->middleware('signed:relative')
        ->name('messages.view-once.file');

    // Disappearing messages (M21)
    Route::put('/conversations/{conversation}/disappearing', [DisappearingMessageController::class, 'update'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-actions')
        ->name('conversations.disappearing');

    // Poll votes (M20)
    Route::put('/messages/{message}/vote', [PollVoteController::class, 'update'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.vote');

    // Contact card as a vCard (M19)
    Route::get('/messages/{message}/contact.vcf', [ContactCardController::class, 'show'])
        ->whereNumber('message')
        ->name('messages.contact');

    // Live location (M18)
    Route::patch('/messages/{message}/location', [LiveLocationController::class, 'update'])
        ->whereNumber('message')
        ->middleware('throttle:live-location')
        ->name('messages.location.update');
    Route::delete('/messages/{message}/location', [LiveLocationController::class, 'destroy'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.location.stop');

    // Stickers and GIF search (M17)
    Route::get('/stickers', [StickerController::class, 'index'])->name('stickers.index');
    Route::post('/stickers', [StickerController::class, 'store'])
        ->middleware('throttle:chat-actions')
        ->name('stickers.store');
    Route::get('/stickers/{sticker}/image', [StickerController::class, 'image'])
        ->whereNumber('sticker')
        ->name('stickers.image');
    Route::delete('/stickers/{sticker}', [StickerController::class, 'destroy'])
        ->whereNumber('sticker')
        ->middleware('throttle:chat-actions')
        ->name('stickers.destroy');
    Route::post('/messages/{message}/sticker', [StickerController::class, 'saveFromMessage'])
        ->whereNumber('message')
        ->middleware('throttle:chat-actions')
        ->name('messages.sticker.save');
    Route::get('/gifs', [GifController::class, 'index'])
        ->middleware('throttle:chat-search')
        ->name('gifs.index');

    Route::post('/link-preview', [LinkPreviewController::class, 'show'])
        ->middleware('throttle:link-preview')
        ->name('link-previews.show');
    Route::get('/link-previews/{linkPreview}/image', [LinkPreviewController::class, 'image'])
        ->whereNumber('linkPreview')
        ->name('link-previews.image');

    // Delivery & read receipts
    Route::post('/conversations/{conversation}/seen', [MessageStatusController::class, 'seen'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-actions')
        ->name('conversations.seen');
    Route::post('/messages/delivered', [MessageStatusController::class, 'delivered'])
        ->middleware('throttle:chat-actions')
        ->name('messages.delivered');

    // Realtime: typing, presence, polling fallback
    Route::post('/conversations/{conversation}/typing', [PresenceController::class, 'typing'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-typing')
        ->name('conversations.typing');
    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat'])->middleware('throttle:chat-actions')->name('presence.heartbeat');
    Route::post('/presence/offline', [PresenceController::class, 'offline'])->middleware('throttle:chat-actions')->name('presence.offline');
    Route::get('/chat/sync', SyncController::class)->middleware('throttle:chat-sync')->name('chat.sync');

    // Users
    // Voice & video calls
    Route::post('/conversations/{conversation}/calls', [CallController::class, 'store'])
        ->whereNumber('conversation')
        ->middleware('throttle:calls-start')
        ->name('calls.store');
    Route::get('/calls/active', [CallController::class, 'active'])->middleware('throttle:chat-actions')->name('calls.active');
    // Communities (G10)
    Route::get('/communities', [CommunityController::class, 'index'])->name('communities.index');
    Route::post('/communities', [CommunityController::class, 'store'])->middleware('throttle:chat-actions')->name('communities.store');
    Route::prefix('/communities/{community}')->whereNumber('community')->middleware('throttle:chat-actions')->group(function () {
        Route::get('/', [CommunityController::class, 'show'])->name('communities.show');
        Route::post('/', [CommunityController::class, 'update'])->name('communities.update');
        Route::delete('/', [CommunityController::class, 'destroy'])->name('communities.destroy');
        Route::post('/leave', [CommunityController::class, 'leave'])->name('communities.leave');
        Route::delete('/members/{user}', [CommunityController::class, 'removeMember'])->whereNumber('user')->name('communities.members.destroy');
        Route::post('/groups', [CommunityController::class, 'storeGroup'])->name('communities.groups.store');
        Route::post('/groups/{conversation}', [CommunityController::class, 'linkGroup'])->whereNumber('conversation')->name('communities.groups.link');
        Route::delete('/groups/{conversation}', [CommunityController::class, 'unlinkGroup'])->whereNumber('conversation')->name('communities.groups.unlink');
        Route::post('/groups/{conversation}/join', [CommunityController::class, 'joinGroup'])->whereNumber('conversation')->name('communities.groups.join');
        Route::get('/invite', [CommunityController::class, 'invite'])->name('communities.invite');
        Route::post('/invite', [CommunityController::class, 'invite'])->name('communities.invite.reset');
    });
    Route::get('/community/{token}', [CommunityController::class, 'joinPage'])->where('token', '[A-Za-z0-9]{16,40}')->name('communities.join.show');
    Route::post('/community/{token}', [CommunityController::class, 'join'])->where('token', '[A-Za-z0-9]{16,40}')->middleware('throttle:chat-actions')->name('communities.join');

    // Status (Phase 5)
    Route::get('/statuses', [StatusController::class, 'index'])->name('statuses.index');
    Route::post('/statuses', [StatusController::class, 'store'])->middleware('throttle:chat-send')->name('statuses.store');
    Route::get('/status/privacy', [StatusController::class, 'privacy'])->name('statuses.privacy');
    Route::put('/status/privacy', [StatusController::class, 'updatePrivacy'])->middleware('throttle:chat-actions')->name('statuses.privacy.update');
    Route::post('/statuses/mutes/{user}', [StatusController::class, 'mute'])->whereNumber('user')->middleware('throttle:chat-actions')->name('statuses.mute');
    Route::delete('/statuses/mutes/{user}', [StatusController::class, 'mute'])->whereNumber('user')->middleware('throttle:chat-actions')->name('statuses.unmute');
    Route::prefix('/statuses/{status}')->whereNumber('status')->group(function () {
        Route::delete('/', [StatusController::class, 'destroy'])->middleware('throttle:chat-actions')->name('statuses.destroy');
        Route::get('/media', [StatusController::class, 'media'])->name('statuses.media');
        Route::post('/view', [StatusController::class, 'view'])->middleware('throttle:chat-actions')->name('statuses.view');
        Route::get('/viewers', [StatusController::class, 'viewers'])->name('statuses.viewers');
        Route::post('/reply', [StatusController::class, 'reply'])->middleware('throttle:chat-send')->name('statuses.reply');
        Route::post('/react', [StatusController::class, 'react'])->middleware('throttle:chat-send')->name('statuses.react');
    });

    // Channels (G11)
    Route::get('/channels', [ChannelController::class, 'index'])->middleware('throttle:chat-search')->name('channels.index');
    Route::post('/channels', [ChannelController::class, 'store'])->middleware('throttle:chat-actions')->name('channels.store');
    Route::prefix('/channels/{conversation}')->whereNumber('conversation')->middleware('throttle:chat-actions')->group(function () {
        Route::get('/', [ChannelController::class, 'show'])->name('channels.show');
        Route::post('/', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('/', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('/follow', [ChannelController::class, 'follow'])->name('channels.follow');
        Route::delete('/follow', [ChannelController::class, 'unfollow'])->name('channels.unfollow');
    });
    Route::get('/channel/{token}', [ChannelController::class, 'linkPage'])->where('token', '[A-Za-z0-9]{16,40}')->name('channels.link');

    // Broadcast lists (G9)
    Route::post('/broadcasts', [BroadcastController::class, 'store'])->middleware('throttle:chat-actions')->name('broadcasts.store');
    Route::patch('/broadcasts/{conversation}', [BroadcastController::class, 'update'])->whereNumber('conversation')->middleware('throttle:chat-actions')->name('broadcasts.update');
    Route::delete('/broadcasts/{conversation}', [BroadcastController::class, 'destroy'])->whereNumber('conversation')->middleware('throttle:chat-actions')->name('broadcasts.destroy');

    // Group chats (Phase 4)
    Route::get('/messages/{message}/receipts', [GroupController::class, 'receipts'])->middleware('throttle:chat-actions')->name('messages.receipts');
    Route::post('/groups', [GroupController::class, 'store'])->middleware('throttle:chat-actions')->name('groups.store');
    Route::prefix('/groups/{conversation}')->whereNumber('conversation')->middleware('throttle:chat-actions')->group(function () {
        Route::post('/', [GroupController::class, 'update'])->name('groups.update');
        Route::delete('/', [GroupController::class, 'destroy'])->name('groups.destroy');
        Route::patch('/settings', [GroupController::class, 'settings'])->name('groups.settings');
        Route::post('/members', [GroupController::class, 'addMembers'])->name('groups.members.store');
        Route::patch('/members/{user}', [GroupController::class, 'updateMember'])->whereNumber('user')->name('groups.members.update');
        Route::delete('/members/{user}', [GroupController::class, 'removeMember'])->whereNumber('user')->name('groups.members.destroy');
        Route::post('/leave', [GroupController::class, 'leave'])->name('groups.leave');
        Route::get('/invite', [GroupController::class, 'invite'])->name('groups.invite');
        Route::post('/invite', [GroupController::class, 'invite'])->name('groups.invite.reset');
    });
    Route::get('/join/{token}', [GroupController::class, 'joinPage'])->where('token', '[A-Za-z0-9]{16,40}')->name('groups.join.show');
    Route::post('/join/{token}', [GroupController::class, 'join'])->where('token', '[A-Za-z0-9]{16,40}')->middleware('throttle:chat-actions')->name('groups.join');

    // Call links (K7)
    Route::get('/call/{token}', [CallLinkController::class, 'show'])->where('token', '[A-Za-z0-9]{16,40}')->name('call-links.show');
    Route::get('/call-links', [CallLinkController::class, 'index'])->name('call-links.index');
    Route::post('/call-links', [CallLinkController::class, 'store'])->middleware('throttle:chat-actions')->name('call-links.store');
    Route::delete('/call-links/{callLink}', [CallLinkController::class, 'destroy'])->middleware('throttle:chat-actions')->name('call-links.destroy');
    Route::post('/call-links/{token}/join', [CallLinkController::class, 'join'])->where('token', '[A-Za-z0-9]{16,40}')->middleware('throttle:calls-start')->name('call-links.join');

    // Group calls (K6)
    Route::post('/call-rooms', [CallRoomController::class, 'store'])->middleware('throttle:calls-start')->name('call-rooms.store');
    Route::prefix('/call-rooms/{room}')->whereNumber('room')->group(function () {
        Route::get('/', [CallRoomController::class, 'show'])->middleware('throttle:calls-signal')->name('call-rooms.show');
        Route::post('/participants', [CallRoomController::class, 'invite'])->middleware('throttle:calls-start')->name('call-rooms.invite');
        Route::post('/leave', [CallRoomController::class, 'leave'])->middleware('throttle:chat-actions')->name('call-rooms.leave');
        Route::post('/heartbeat', [CallRoomController::class, 'heartbeat'])->middleware('throttle:chat-actions')->name('call-rooms.heartbeat');
        Route::post('/signals', [CallRoomController::class, 'storeSignal'])->middleware('throttle:calls-signal')->name('call-rooms.signals.store');
        Route::get('/signals', [CallRoomController::class, 'signals'])->middleware('throttle:calls-signal')->name('call-rooms.signals');
    });

    // Calls tab (K1)
    Route::get('/calls', [CallLogController::class, 'index'])->name('calls.log');
    Route::post('/calls/seen', [CallLogController::class, 'seen'])->middleware('throttle:chat-actions')->name('calls.log.seen');
    Route::delete('/calls', [CallLogController::class, 'clear'])->middleware('throttle:chat-actions')->name('calls.log.clear');
    Route::delete('/calls/{call}', [CallLogController::class, 'destroy'])->whereNumber('call')->middleware('throttle:chat-actions')->name('calls.log.destroy');
    Route::prefix('/calls/{call}')->whereNumber('call')->group(function () {
        Route::get('/', [CallController::class, 'show'])->middleware('throttle:calls-signal')->name('calls.show');
        Route::post('/ringing', [CallController::class, 'ringing'])->middleware('throttle:chat-actions')->name('calls.ringing');
        Route::post('/accept', [CallController::class, 'accept'])->middleware('throttle:chat-actions')->name('calls.accept');
        Route::post('/decline', [CallController::class, 'decline'])->middleware('throttle:chat-actions')->name('calls.decline');
        Route::post('/end', [CallController::class, 'end'])->middleware('throttle:chat-actions')->name('calls.end');
        Route::post('/heartbeat', [CallController::class, 'heartbeat'])->middleware('throttle:chat-actions')->name('calls.heartbeat');
        Route::post('/video', [CallController::class, 'video'])->middleware('throttle:chat-actions')->name('calls.video');
        Route::post('/participants', [CallRoomController::class, 'addToCall'])->middleware('throttle:calls-start')->name('calls.participants.store');
        Route::post('/signals', [CallController::class, 'storeSignal'])->middleware('throttle:calls-signal')->name('calls.signals.store');
        Route::get('/signals', [CallController::class, 'signals'])->middleware('throttle:calls-signal')->name('calls.signals');
    });

    Route::get('/users/search', [UserController::class, 'search'])->middleware('throttle:chat-search')->name('users.search');
    Route::get('/users/online', [UserController::class, 'online'])->name('users.online');
    Route::post('/users/{user}/report', [ReportController::class, 'store'])->whereNumber('user')->middleware('throttle:chat-actions')->name('users.report');

    // Block / unblock
    Route::post('/users/{user}/block', [BlockController::class, 'store'])
        ->whereNumber('user')
        ->middleware('throttle:chat-actions')
        ->name('blocks.store');
    Route::delete('/users/{user}/block', [BlockController::class, 'destroy'])
        ->whereNumber('user')
        ->middleware('throttle:chat-actions')
        ->name('blocks.destroy');

    /*
    |----------------------------------------------------------------------
    | Admin panel (separate "admin" middleware)
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::get('/users/export', [UserDataController::class, 'export'])->middleware('throttle:chat-export')->name('users.export');
        Route::post('/users/bulk', [UserDataController::class, 'bulk'])->name('users.bulk');
        Route::get('/users/{user}', [AdminController::class, 'showUser'])->whereNumber('user')->name('users.show');
        Route::get('/users/{user}/data', [UserDataController::class, 'data'])->whereNumber('user')->middleware('throttle:chat-export')->name('users.data');
        Route::delete('/users/{user}/sessions/{key}', [UserDataController::class, 'endSession'])->whereNumber('user')->where('key', '[a-f0-9]{40}')->name('users.sessions.destroy');
        Route::patch('/users/{user}/status', [AdminController::class, 'updateStatus'])->whereNumber('user')->name('users.status');
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->whereNumber('user')->name('users.destroy');
        Route::prefix('/users/{user}')->whereNumber('user')->name('users.')->group(function () {
            Route::put('/', [UserModerationController::class, 'update'])->name('update');
            Route::post('/ban', [UserModerationController::class, 'ban'])->name('ban');
            Route::delete('/ban', [UserModerationController::class, 'unban'])->name('unban');
            Route::patch('/role', [UserModerationController::class, 'role'])->name('role');
            Route::post('/logout', [UserModerationController::class, 'logout'])->name('logout');
            Route::delete('/photo', [UserModerationController::class, 'removePhoto'])->name('photo');
            Route::delete('/two-step', [UserModerationController::class, 'resetTwoStep'])->name('two-step');
        });

        // Chats and messages (every chat opened is recorded in the audit log)
        Route::get('/chats', [AdminChatController::class, 'index'])->name('chats');
        Route::get('/chats/{conversation}', [AdminChatController::class, 'show'])->whereNumber('conversation')->name('chats.show');
        Route::get('/messages', [AdminChatController::class, 'search'])->middleware('throttle:chat-search')->name('messages');
        Route::get('/messages/{message}/attachment', [AdminChatController::class, 'attachment'])->whereNumber('message')->name('messages.attachment');
        Route::delete('/messages/{message}', [AdminChatController::class, 'destroyMessage'])->whereNumber('message')->name('messages.destroy');

        // Groups, channels, communities, status updates
        Route::get('/groups', [SpaceController::class, 'groups'])->name('groups');
        Route::get('/groups/{conversation}', [SpaceController::class, 'group'])->whereNumber('conversation')->name('groups.show');
        Route::delete('/groups/{conversation}', [SpaceController::class, 'destroyGroup'])->whereNumber('conversation')->name('groups.destroy');
        Route::get('/channels', [SpaceController::class, 'channels'])->name('channels');
        Route::get('/channels/{conversation}', [SpaceController::class, 'channel'])->whereNumber('conversation')->name('channels.show');
        Route::delete('/channels/{conversation}', [SpaceController::class, 'destroyChannel'])->whereNumber('conversation')->name('channels.destroy');
        Route::get('/communities', [SpaceController::class, 'communities'])->name('communities');
        Route::get('/communities/{community}', [SpaceController::class, 'community'])->whereNumber('community')->name('communities.show');
        Route::delete('/communities/{community}', [SpaceController::class, 'destroyCommunity'])->whereNumber('community')->name('communities.destroy');
        Route::get('/statuses', [SpaceController::class, 'statuses'])->name('statuses');
        Route::get('/statuses/{status}/media', [SpaceController::class, 'statusMedia'])->whereNumber('status')->name('statuses.media');
        Route::delete('/statuses/{status}', [SpaceController::class, 'destroyStatus'])->whereNumber('status')->name('statuses.destroy');

        // Audit log and app settings
        Route::get('/audit', [SystemController::class, 'audit'])->name('audit');
        // Server backups (D8)
        Route::get('/backups', [AdminBackupController::class, 'index'])->name('backups');
        Route::post('/backups', [AdminBackupController::class, 'store'])->middleware('throttle:chat-export')->name('backups.store');
        Route::get('/backups/{backup}/download', [AdminBackupController::class, 'download'])->whereNumber('backup')->name('backups.download');
        Route::delete('/backups/{backup}', [AdminBackupController::class, 'destroy'])->whereNumber('backup')->name('backups.destroy');
        Route::get('/settings', [SystemController::class, 'settings'])->name('settings');
        Route::put('/settings', [SystemController::class, 'updateSettings'])->name('settings.update');
        Route::put('/app-release', [AppReleaseController::class, 'update'])->name('app-release.update');
        Route::post('/settings/test-sms', [SystemController::class, 'testSms'])->middleware('throttle:chat-lock')->name('settings.test-sms');
        Route::post('/settings/test-mail', [SystemController::class, 'testMail'])->middleware('throttle:chat-lock')->name('settings.test-mail');
        // Reports (Phase 6, P6)
        Route::get('/reports', [ReportController::class, 'index'])->name('reports');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->whereNumber('report')->name('reports.show');
        Route::patch('/reports/{report}', [ReportController::class, 'update'])->whereNumber('report')->name('reports.update');
    });

    // In-app notification centre
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'markRead'])
        ->middleware('throttle:chat-actions')
        ->name('notifications.read');

    // Profile settings
    Route::get('/settings', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/settings/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::match(['put', 'patch'], '/settings/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    // Wallpaper for all chats (D2)
    Route::post('/settings/wallpaper', [ChatPreferencesController::class, 'updateWallpaper'])->middleware('throttle:chat-actions')->name('settings.wallpaper.update');
    Route::get('/settings/wallpaper', [ChatPreferencesController::class, 'showWallpaper'])->name('settings.wallpaper.show');
    // Business tools (X8)
    Route::put('/settings/business', [BusinessController::class, 'saveProfile'])->name('business.profile');
    Route::put('/settings/business/messages', [BusinessController::class, 'saveMessages'])->name('business.messages');
    Route::delete('/settings/business', [BusinessController::class, 'destroy'])->name('business.destroy');
    Route::get('/quick-replies', [BusinessController::class, 'quickReplies'])->name('quick-replies.index');
    Route::post('/quick-replies', [BusinessController::class, 'storeQuickReply'])->middleware('throttle:chat-actions')->name('quick-replies.store');
    Route::put('/quick-replies/{quickReply}', [BusinessController::class, 'updateQuickReply'])->whereNumber('quickReply')->name('quick-replies.update');
    Route::delete('/quick-replies/{quickReply}', [BusinessController::class, 'destroyQuickReply'])->whereNumber('quickReply')->name('quick-replies.destroy');
    Route::get('/users/{user}/business', [BusinessController::class, 'show'])->whereNumber('user')->name('users.business');

    // Manage storage (D6)
    Route::get('/settings/storage', [StorageController::class, 'summary'])->middleware('throttle:chat-search')->name('storage.summary');
    Route::get('/settings/storage/files', [StorageController::class, 'files'])->middleware('throttle:chat-search')->name('storage.files');
    Route::post('/settings/storage/delete', [StorageController::class, 'destroy'])->middleware('throttle:chat-actions')->name('storage.delete');
    // Chat backup (D8)
    Route::get('/settings/backups', [BackupController::class, 'show'])->name('backups.show');
    Route::post('/settings/backups', [BackupController::class, 'store'])->middleware('throttle:chat-export')->name('backups.store');
    Route::get('/settings/backups/{backup}/download', [BackupController::class, 'download'])->whereNumber('backup')->name('backups.download');
    Route::delete('/settings/backups/{backup}', [BackupController::class, 'destroy'])->whereNumber('backup')->name('backups.destroy');
    // Account (Phase 7): change number, profile QR code, download my data, delete my account
    Route::post('/settings/phone', [PhoneChangeController::class, 'start'])->middleware('throttle:otp')->name('phone.change');
    Route::post('/settings/phone/code', [PhoneChangeController::class, 'verify'])->middleware('throttle:chat-lock')->name('phone.change.verify');
    Route::post('/settings/phone/resend', [PhoneChangeController::class, 'resend'])->middleware('throttle:otp')->name('phone.change.resend');
    Route::delete('/settings/phone', [PhoneChangeController::class, 'cancel'])->name('phone.change.cancel');
    Route::get('/settings/qr', [ProfileQrController::class, 'show'])->name('profile-qr.show');
    Route::post('/settings/qr/reset', [ProfileQrController::class, 'reset'])->middleware('throttle:chat-actions')->name('profile-qr.reset');
    Route::get('/u/{token}', [ProfileQrController::class, 'page'])->where('token', '[A-Za-z0-9]{32}')->name('profile-qr.page');
    Route::post('/qr/lookup', [ProfileQrController::class, 'lookup'])->middleware('throttle:chat-actions')->name('profile-qr.lookup');
    Route::get('/settings/export', [AccountController::class, 'export'])->middleware('throttle:account-export')->name('account.export');
    Route::delete('/settings/account', [AccountController::class, 'destroy'])->middleware('throttle:chat-lock')->name('account.destroy');

    // Linked devices: approve a computer's QR code / code from this phone (P10)
    Route::get('/link-device/{token}', [LinkedDeviceController::class, 'page'])->where('token', '[A-Za-z0-9]{40}')->name('devices.link');
    Route::post('/linked-devices/lookup', [LinkedDeviceController::class, 'lookup'])->middleware('throttle:chat-lock')->name('linked-devices.lookup');
    Route::post('/linked-devices/approve', [LinkedDeviceController::class, 'approve'])->middleware('throttle:chat-lock')->name('linked-devices.approve');

    // Where you're signed in (P9)
    Route::get('/settings/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('/settings/sessions/others', [SessionController::class, 'destroyOthers'])->middleware('throttle:chat-actions')->name('sessions.others');
    Route::delete('/settings/sessions/{key}', [SessionController::class, 'destroy'])->where('key', '[a-f0-9]{40}')->middleware('throttle:chat-actions')->name('sessions.destroy');
    Route::post('/settings/two-step', [TwoStepController::class, 'enable'])->middleware('throttle:chat-lock')->name('two-step.enable');
    Route::put('/settings/two-step', [TwoStepController::class, 'change'])->middleware('throttle:chat-lock')->name('two-step.change');
    Route::delete('/settings/two-step', [TwoStepController::class, 'disable'])->middleware('throttle:chat-lock')->name('two-step.disable');
    Route::delete('/settings/two-step/devices', [TwoStepController::class, 'forgetDevices'])->name('two-step.devices.forget');
});
