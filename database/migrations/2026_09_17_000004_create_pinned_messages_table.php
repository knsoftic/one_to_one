<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages pinned to the top of a chat (for both people, up to three, for a limited time).
     */
    public function up(): void
    {
        Schema::create('pinned_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pinned_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->unique(['conversation_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinned_messages');
    }
};
