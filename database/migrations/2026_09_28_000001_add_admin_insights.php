<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin panel at scale: indexes for per-day and per-person statistics, and a sign-in
 * history (successful and failed sign-ins, sign-outs) for every account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['sender_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('last_seen');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::create('user_logins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 10); // login | failed | logout
            $table->string('method', 20)->nullable(); // password | phone_code | two_step | qr | register | remembered
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_logins');

        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['sender_id', 'created_at']);
            $table->dropIndex(['created_at']);
        });
    }
};
