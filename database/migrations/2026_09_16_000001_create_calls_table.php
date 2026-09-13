<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-to-one voice and video calls (WebRTC).
     *
     * - calls: one row per call attempt; status ringing → ongoing → ended, with the reason
     *   (completed, declined, missed, cancelled, busy, failed). caller_client / callee_client
     *   identify the browser tab or phone taking part, so other devices of the same user stay quiet.
     * - call_signals: WebRTC offers, answers and ICE candidates relayed between the two devices.
     *   Kept only while the call is active.
     */
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10); // audio | video
            $table->string('status', 20)->default('ringing'); // ringing | ongoing | ended
            $table->string('end_reason', 20)->nullable();
            $table->string('caller_client', 64)->nullable();
            $table->string('callee_client', 64)->nullable();
            $table->timestamp('ringing_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('duration')->nullable(); // seconds, answered calls only
            $table->timestamp('caller_seen_at')->nullable();
            $table->timestamp('callee_seen_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();

            $table->index(['caller_id', 'status']);
            $table->index(['callee_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('call_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_client', 64);
            $table->string('to_client', 64)->nullable();
            $table->string('type', 20); // offer | answer | candidate | media
            $table->mediumText('payload');
            $table->timestamp('created_at')->nullable();

            $table->index(['call_id', 'recipient_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_signals');
        Schema::dropIfExists('calls');
    }
};
