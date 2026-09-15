<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — wallpaper (D2), font size (D3), notification tone and vibration (D4)
 * and auto-download (D5); wallpaper and tone can also be set per chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = the app's own dotted background.
            $table->string('wallpaper', 20)->nullable()->after('theme');
            $table->string('wallpaper_path')->nullable()->after('wallpaper');
            $table->unsignedTinyInteger('wallpaper_dim')->default(0)->after('wallpaper_path');
            $table->string('font_size', 10)->default('medium')->after('wallpaper_dim');
            $table->string('notification_tone', 20)->default('default')->after('notification_sound');
            $table->string('notification_vibrate', 10)->default('default')->after('notification_tone');
            $table->json('auto_download')->nullable()->after('notification_vibrate');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            // null = use the person's default from Settings.
            $table->string('wallpaper', 20)->nullable();
            $table->string('wallpaper_path')->nullable();
            $table->string('notification_tone', 20)->nullable();
            $table->string('notification_vibrate', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn(['wallpaper', 'wallpaper_path', 'notification_tone', 'notification_vibrate']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wallpaper', 'wallpaper_path', 'wallpaper_dim', 'font_size', 'notification_tone', 'notification_vibrate', 'auto_download']);
        });
    }
};
