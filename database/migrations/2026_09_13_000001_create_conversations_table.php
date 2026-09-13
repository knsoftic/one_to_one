<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A conversation is a private channel between exactly two users.
     *
     * Participants are always stored in ascending order
     * (user_one_id < user_two_id, enforced by the Conversation model), so the
     * unique index below guarantees that only one conversation can ever exist
     * for a given pair of users, regardless of who started it.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            // Foreign key is added in the messages migration (circular reference).
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamps();

            $table->unique(['user_one_id', 'user_two_id'], 'conversations_participants_unique');
            $table->index(['user_two_id', 'user_one_id']);
            $table->index('last_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
