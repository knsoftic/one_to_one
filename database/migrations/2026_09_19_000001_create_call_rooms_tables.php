<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K6 — Group calls (up to 4 people, every device connected to every other).
 *
 * - call_rooms: one group call; everyone taking part is in call_room_participants.
 * - Each person invited is rung with a normal row in `calls` (call_room_id set), so
 *   ringing, answering, declining, phone pushes and call history work as for one-to-one calls.
 * - call_signals can belong to a room: WebRTC messages between two people in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10); // audio | video
            $table->string('status', 20)->default('active'); // active | ended
            $table->unsignedTinyInteger('max_participants')->default(4);
            // Call link (K7) this room was opened from; a link can open a new room after the last one ended.
            $table->string('link_token', 40)->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::create('call_room_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20); // ringing | joined | left | declined | missed
            $table->string('client_id', 64)->nullable();
            $table->unsignedInteger('join_seq')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['call_room_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('call_room_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
        });

        Schema::table('call_signals', function (Blueprint $table) {
            $table->foreignId('call_room_id')->nullable()->after('call_id')->constrained()->cascadeOnDelete();
            $table->index(['call_room_id', 'recipient_id', 'id']);
        });

        Schema::table('call_signals', function (Blueprint $table) {
            $table->unsignedBigInteger('call_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('call_signals', function (Blueprint $table) {
            // The foreign key uses the room index: remove the key, then the index and the column.
            $table->dropForeign(['call_room_id']);
            $table->dropIndex(['call_room_id', 'recipient_id', 'id']);
            $table->dropColumn('call_room_id');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('call_room_id');
        });

        Schema::dropIfExists('call_room_participants');
        Schema::dropIfExists('call_rooms');
    }
};
