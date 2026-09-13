<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageStatusController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;
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
    Route::get('/messages/{message}/attachment', [AttachmentController::class, 'show'])
        ->whereNumber('message')
        ->name('messages.attachment');

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
