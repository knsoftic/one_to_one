<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G10 — Communities: several groups under one roof, with an announcement group.
 *
 * Everyone in a community is in its announcement group (only admins post there);
 * the community's admins are that group's admins. Groups belong to a community
 * through conversations.community_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 512)->nullable();
            $table->string('avatar')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invite_token', 40)->nullable()->unique();
            $table->timestamps();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('community_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->boolean('is_announcement')->default(false)->after('community_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('community_id');
            $table->dropColumn('is_announcement');
        });

        Schema::dropIfExists('communities');
    }
};
