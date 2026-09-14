<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CallLinkController;
use App\Http\Controllers\CallLogController;
use App\Http\Controllers\CallRoomController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatListController;
use App\Http\Controllers\ChatLockController;
use App\Http\Controllers\ChatSettingsController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\ContactCardController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DisappearingMessageController;
use App\Http\Controllers\GifController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LinkPreviewController;
use App\Http\Controllers\LiveLocationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessagePinController;
use App\Http\Controllers\MessageReactionController;
use App\Http\Controllers\MessageStatusController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PollVoteController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StarredMessageController;
use App\Http\Controllers\StickerController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ViewOnceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/

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
        Route::get('/users/{user}', [AdminController::class, 'showUser'])->whereNumber('user')->name('users.show');
        Route::patch('/users/{user}/status', [AdminController::class, 'updateStatus'])->whereNumber('user')->name('users.status');
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->whereNumber('user')->name('users.destroy');
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
});
