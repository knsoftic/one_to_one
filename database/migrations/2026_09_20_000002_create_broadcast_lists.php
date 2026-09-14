<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G9 — Broadcast lists.
 *
 * A broadcast list is a conversation of type "broadcast" that only its owner is in.
 * Its recipients are in broadcast_recipients. Every message sent to the list is
 * copied into the one-to-one chat with each recipient; copies point to the
 * original with messages.broadcast_message_id (for "Read by" / "Delivered to").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('broadcast_message_id')->nullable()->after('reply_to_id')->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('broadcast_message_id');
        });

        Schema::dropIfExists('broadcast_recipients');
    }
};
