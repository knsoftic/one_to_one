<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person's own chat lists ("Family", "Work"…) shown as filters (C6).
     * Favourites live in chat_settings.favorite_at.
     */
    public function up(): void
    {
        Schema::create('chat_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('chat_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['chat_list_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_list_items');
        Schema::dropIfExists('chat_lists');
    }
};
