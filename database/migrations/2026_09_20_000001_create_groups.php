<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Group chats.
 *
 * - conversations.type: "direct" (the two people in user_one_id / user_two_id) or "group".
 *   Groups have no user_one_id / user_two_id; their people are in conversation_members.
 * - conversation_members: who is (or was) in a group, their role and how far they have read.
 *   visible_from / visible_until limit the messages a member sees to the time they were in it.
 * - message_receipts: per member delivery and read times of group messages ("Read by", G6).
 *   messages.delivered_at / seen_at are set once every member has received / read a message.
 * - message_hides: "Delete for me" of a group message by someone other than its sender.
 * - messages.receiver_id is empty for group messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type', 20)->default('direct')->after('id');
            $table->string('name', 100)->nullable()->after('type');
            $table->string('description', 512)->nullable()->after('name');
            $table->string('avatar')->nullable()->after('description');
            $table->foreignId('created_by')->nullable()->after('avatar')->constrained('users')->nullOnDelete();
            $table->boolean('only_admins_send')->default(false);
            $table->boolean('only_admins_edit')->default(false);
            $table->string('invite_token', 40)->nullable()->unique();
            $table->timestamp('ended_at')->nullable();

            $table->index('type');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_one_id')->nullable()->change();
            $table->unsignedBigInteger('user_two_id')->nullable()->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('receiver_id')->nullable()->change();
        });

        Schema::create('conversation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member'); // admin | member
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedBigInteger('visible_from_message_id')->default(0);
            $table->unsignedBigInteger('visible_until_message_id')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->unsignedBigInteger('last_delivered_message_id')->default(0);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('message_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('seen_at')->nullable();

            $table->unique(['message_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('message_hides', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['message_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_hides');
        Schema::dropIfExists('message_receipts');
        Schema::dropIfExists('conversation_members');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropUnique(['invite_token']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'name', 'description', 'avatar', 'only_admins_send', 'only_admins_edit', 'invite_token', 'ended_at']);
        });
    }
};
