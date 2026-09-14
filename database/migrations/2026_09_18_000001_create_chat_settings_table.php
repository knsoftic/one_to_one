<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How one person keeps a chat in their list (Phase 2): pinned, muted,
     * archived, marked unread, favourite, cleared/deleted and locked.
     * The other person never sees these settings.
     */
    public function up(): void
    {
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('pinned_at')->nullable();
            // datetime (not timestamp): "mute always" is stored far in the future, past 2038.
            $table->dateTime('muted_until')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->boolean('marked_unread')->default(false);
            $table->timestamp('favorite_at')->nullable();
            // Messages up to this id are hidden for this person ("Clear chat" / "Delete chat").
            $table->unsignedBigInteger('cleared_message_id')->nullable();
            $table->timestamp('cleared_at')->nullable();
            // "Delete chat": out of the list until a new message arrives.
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_settings');
    }
};
