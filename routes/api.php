<?php

use App\Http\Controllers\DeviceApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile app (prefix /api)
|--------------------------------------------------------------------------
|
| Stateless endpoints authenticated with the device token issued by
| POST /devices: push token, background connection, and the actions on a
| message notification (delivered ✓✓, Reply, Mark as read).
|
*/

Route::middleware(['device', 'throttle:device-api'])->prefix('device')->group(function () {
    Route::post('/broadcasting/auth', [DeviceApiController::class, 'broadcastingAuth'])->name('device.broadcasting.auth');
    Route::get('/notifications', [DeviceApiController::class, 'notifications'])->name('device.notifications');
    Route::put('/push-token', [DeviceApiController::class, 'pushToken'])->name('device.push-token');
    Route::post('/messages/delivered', [DeviceApiController::class, 'delivered'])->name('device.delivered');
    Route::post('/conversations/{conversation}/messages', [DeviceApiController::class, 'reply'])
        ->whereNumber('conversation')
        ->middleware('throttle:chat-send')
        ->name('device.reply');
    Route::post('/conversations/{conversation}/read', [DeviceApiController::class, 'read'])
        ->whereNumber('conversation')
        ->name('device.read');
    Route::delete('/', [DeviceApiController::class, 'revoke'])->name('device.revoke');
});
