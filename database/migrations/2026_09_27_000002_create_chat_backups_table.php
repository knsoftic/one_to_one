<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D8 — chat backups: a person's own backup file (Settings → Storage and data) and
 * full server backups made from the admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_backups', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // personal | server
            // The owner of a personal backup; the admin who asked for a server backup.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('pending'); // pending | working | ready | failed
            $table->boolean('include_media')->default(false);
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('stats')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'status']);
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_backups');
    }
};
