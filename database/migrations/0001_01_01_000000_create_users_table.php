<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('username', 30)->unique();
            $table->string('email', 191)->unique();
            $table->string('phone', 20)->unique();
            $table->string('profile_image')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Presence
            $table->boolean('is_online')->default(false);
            $table->timestamp('last_seen')->nullable();

            // Access control
            $table->string('role', 20)->default('user');       // user | admin
            $table->string('status', 20)->default('active');   // active | inactive | suspended

            // Preferences
            $table->string('theme', 10)->default('system');    // light | dark | system
            $table->boolean('notifications_enabled')->default(true);
            $table->boolean('notification_sound')->default(true);

            $table->rememberToken();
            $table->timestamps();

            $table->index('name');
            $table->index(['is_online', 'last_seen']);
            $table->index(['status', 'role']);
            $table->index('created_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
