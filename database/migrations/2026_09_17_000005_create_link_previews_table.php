<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared cache of fetched pages (one row per link), referenced by messages.
        Schema::create('link_previews', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->string('title', 300)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('site_name', 120)->nullable();
            $table->string('image')->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->boolean('failed')->default(false);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('link_preview_id')->nullable()->after('forward_count')->constrained('link_previews')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('link_preview_id');
        });

        Schema::dropIfExists('link_previews');
    }
};
