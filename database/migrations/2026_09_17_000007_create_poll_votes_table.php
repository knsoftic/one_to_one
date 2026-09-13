<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Poll question and options live in the message; one row per chosen option.
        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('option');
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'user_id', 'option']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
    }
};
