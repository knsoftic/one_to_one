<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 — Status (stories).
     *
     * - statuses: a text, photo or video update that lasts 24 hours. Who may see it is
     *   copied from the owner's status privacy when it is posted (S3).
     * - status_views: who saw an update and when (S2), and their reaction (S4).
     * - status_privacy_users: the people in "My contacts except…" / "Only share with…" (S3).
     * - status_mutes: people whose updates are moved down to "Muted updates" (S5).
     */
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->text('body')->nullable();
            $table->string('background', 20)->nullable();
            $table->unsignedTinyInteger('font')->default(0);
            $table->string('attachment')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->json('attachment_meta')->nullable();
            $table->string('privacy', 10)->default('contacts');
            $table->json('privacy_user_ids')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('status_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->constrained('statuses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->string('reaction', 32)->nullable();

            $table->unique(['status_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('status_privacy_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->string('list', 10);

            $table->unique(['user_id', 'list', 'member_id']);
        });

        Schema::create('status_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('muted_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'muted_user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('status_privacy', 10)->default('contacts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status_privacy');
        });

        Schema::dropIfExists('status_mutes');
        Schema::dropIfExists('status_privacy_users');
        Schema::dropIfExists('status_views');
        Schema::dropIfExists('statuses');
    }
};
