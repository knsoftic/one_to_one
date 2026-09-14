<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — Privacy and security.
     *
     * - users: About text (P4), who sees last seen / online / photo / About (P1, P2),
     *   read receipts on or off (P3), two-step verification PIN (P7).
     * - user_reports: reports people send about someone, reviewed in the admin panel (P6).
     * - trusted_devices: browsers that passed two-step verification (P7).
     * - login_links: QR / code logins approved from a signed-in phone (P10).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('about', 139)->nullable()->after('profile_image');
            $table->string('last_seen_privacy', 10)->default('everyone');
            $table->string('online_privacy', 10)->default('everyone');
            $table->string('photo_privacy', 10)->default('everyone');
            $table->string('about_privacy', 10)->default('everyone');
            $table->boolean('read_receipts')->default(true);
            $table->string('two_step_pin')->nullable();
            $table->timestamp('two_step_enabled_at')->nullable();
        });

        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 20);
            $table->text('details')->nullable();
            $table->json('evidence')->nullable();
            $table->boolean('blocked')->default(false);
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['reported_user_id', 'status']);
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('name', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('login_links', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('code_hash', 64)->unique();
            $table->string('secret_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_links');
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('user_reports');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['about', 'last_seen_privacy', 'online_privacy', 'photo_privacy', 'about_privacy', 'read_receipts', 'two_step_pin', 'two_step_enabled_at']);
        });
    }
};
