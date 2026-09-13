<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactCardController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DisappearingMessageController;
use App\Http\Controllers\GifController;
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
    Route::prefix('/calls/{call}')->whereNumber('call')->group(function () {
        Route::get('/', [CallController::class, 'show'])->middleware('throttle:calls-signal')->name('calls.show');
        Route::post('/ringing', [CallController::class, 'ringing'])->middleware('throttle:chat-actions')->name('calls.ringing');
        Route::post('/accept', [CallController::class, 'accept'])->middleware('throttle:chat-actions')->name('calls.accept');
        Route::post('/decline', [CallController::class, 'decline'])->middleware('throttle:chat-actions')->name('calls.decline');
        Route::post('/end', [CallController::class, 'end'])->middleware('throttle:chat-actions')->name('calls.end');
        Route::post('/heartbeat', [CallController::class, 'heartbeat'])->middleware('throttle:chat-actions')->name('calls.heartbeat');
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
