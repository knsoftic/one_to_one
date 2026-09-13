<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Installed mobile apps signed into an account.
     *
     * - token_hash: SHA-256 of the app's access token (the plain token stays on the phone).
     * - fcm_token: Firebase Cloud Messaging registration token for push notifications;
     *   empty when the phone uses the app's own background connection instead.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('fcm_token')->nullable();
            $table->char('fcm_token_hash', 64)->nullable()->unique();
            $table->string('platform', 20)->default('android');
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
