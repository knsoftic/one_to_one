<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();

            $table->text('message')->nullable();
            $table->string('message_type', 20)->default('text'); // text | image | document | voice

            // Attachment (stored on the private "chat" disk)
            $table->string('attachment')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->json('attachment_meta')->nullable(); // thumbnail, dimensions, duration...

            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();

            $table->boolean('deleted_for_sender')->default(false);
            $table->boolean('deleted_for_receiver')->default(false);
            $table->boolean('deleted_for_everyone')->default(false);

            // Delivery status
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('seen_at')->nullable();

            $table->timestamps();

            // Conversation history, newest first / cursor pagination by id.
            $table->index(['conversation_id', 'id']);
            // Unread counters and delivery receipts.
            $table->index(['receiver_id', 'seen_at']);
            $table->index(['receiver_id', 'delivered_at']);
            // Incremental sync (polling fallback).
            $table->index(['sender_id', 'updated_at']);
            $table->index(['receiver_id', 'updated_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('last_message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['last_message_id']);
        });

        Schema::dropIfExists('messages');
    }
};
