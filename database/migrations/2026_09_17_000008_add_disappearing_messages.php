<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Timer for new messages in a chat (null = off).
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedInteger('disappearing_seconds')->nullable()->after('last_message_id');
        });

        // When a message disappears for both people.
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('sent_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('disappearing_seconds');
        });
    }
};
