<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 — Account.
     *
     * - users.phone_verified_at: the number was confirmed with an SMS code (A1, A2).
     * - users.qr_token: the secret in the profile QR code, can be reset (A4).
     * - otp_codes: SMS codes for logging in with the phone number (A1) and changing it (A2).
     *   Only a keyed hash of each code is stored.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->string('qr_token', 40)->nullable()->unique();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('purpose', 20);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at')->useCurrent()->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn(['phone_verified_at', 'qr_token']);
        });
    }
};
