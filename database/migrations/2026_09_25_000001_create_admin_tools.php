<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin panel.
     *
     * - users: bans (reason, when, until — empty = permanent, by whom); status "banned".
     * - admin_audit_logs: what administrators did, including every chat they opened.
     * - app_settings: switches for the whole app (sign-ups open, notice shown to everyone).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ban_reason', 500)->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('banned_until')->nullable()->index();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40)->index();
            $table->string('target_type', 40)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('description', 255);
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banned_by');
            $table->dropIndex(['banned_until']);
            $table->dropColumn(['ban_reason', 'banned_at', 'banned_until']);
        });
    }
};
